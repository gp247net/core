<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\BackupKeyError;
use GP247\Core\Backup\Domain\BackupNotSetUp;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\KeyRing;
use GP247\Core\Backup\Domain\RecoveryKey;
use GP247\Core\Backup\Domain\WrongBackupSecret;
use GP247\Core\Backup\Infrastructure\Crypto\Ecies;
use GP247\Core\Backup\Infrastructure\Crypto\KeyWrap;
use GP247\Core\Backup\Infrastructure\Crypto\OpenSsl;
use GP247\Core\Backup\Infrastructure\Format\CanonicalJson;
use GP247\Core\Backup\Infrastructure\Store\AdminConfigKeyStore;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Site backup key ring: set-up (password + recovery key), password change, new recovery key,
 * unlocking a private key, and the signing key of archive headers.
 *
 * The server keeps only the public key, the wrapped copies of the private key, previous private
 * keys sealed for the current public key, a MAC over all of it, and the signing key (config
 * secret) — nothing that opens an archive without the backup password or the recovery key.
 * APP_KEY never decrypts an archive.
 *
 * Every password change and every new recovery key creates a NEW key pair (security review
 * BK-1 #1): an old secret plus an old archive yields only the old private key, which opens no
 * archive made afterwards. The old private key is kept sealed for the new public key, so older
 * archives on this site open with the current secrets; copies sent elsewhere keep opening with
 * the secrets they were made under.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup, US-backup-engine-encryption
 * @aidlc-adr backup-engine_envelope-encryption, backup-engine_tamper-evidence
 */
final class KeyService
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const KEY_ID = 'backup_key_id';
    public const PUBLIC_KEY = 'backup_public_key';
    public const WRAP_PASSWORD = 'backup_key_wrap_password';
    public const WRAP_RECOVERY = 'backup_key_wrap_recovery';
    public const PREVIOUS = 'backup_key_previous';
    public const RING_MAC = 'backup_key_ring_mac';
    public const SIGNING_KEY = 'backup_signing_key';
    public const CREATED_AT = 'backup_key_created_at';
    private const SIGNING_KEY_BYTES = 32;

    private KeyStore $store;

    /**
     * @param KeyStore|null $store      Where the key ring lives (admin_config by default).
     * @param int           $iterations PBKDF2 iterations for new password wraps.
     */
    public function __construct(?KeyStore $store = null, private readonly int $iterations = KeyWrap::DEFAULT_ITERATIONS)
    {
        $this->store = $store ?? new AdminConfigKeyStore;
    }

    /**
     * @return bool True once a backup password has been set up.
     */
    public function isSetUp(): bool
    {
        return (string) $this->store->get(self::KEY_ID) !== '';
    }

    /**
     * @return int|null Unix time the current key pair was created (set up or last changed), null when unknown.
     *
     * @aidlc-story US-backup-admin-schedule
     */
    public function setUpAt(): ?int
    {
        $value = (string) $this->store->get(self::CREATED_AT);
        $time = $value === '' ? false : strtotime($value);

        return $time === false ? null : $time;
    }

    /**
     * Create the site key pair, wrap it with the password and a new recovery key, create the
     * signing key. The recovery key is returned once and never stored in clear.
     *
     * @param string $password Backup password (≥ 12 characters).
     * @return RecoveryKey Show to the user once.
     * @throws BackupKeyError When already set up or the password is too short.
     */
    public function setup(#[\SensitiveParameter] string $password): RecoveryKey
    {
        if ($this->isSetUp()) {
            throw new BackupKeyError(BackupKeyError::ALREADY_SET_UP);
        }
        $this->assertPassword($password);

        $signingKey = random_bytes(self::SIGNING_KEY_BYTES);
        $recovery = RecoveryKey::generate();
        $values = $this->ringValues(OpenSsl::generateKeyPair(), $this->normalize($password), $recovery, [], $signingKey);
        $values[self::SIGNING_KEY] = base64_encode($signingKey);
        $values[self::CREATED_AT] = gmdate('Y-m-d\TH:i:s\Z');

        try {
            $this->store->putMany($values, [self::SIGNING_KEY], [self::KEY_ID => null]);
        } catch (KeyStoreConflict) {
            throw new BackupKeyError(BackupKeyError::ALREADY_SET_UP);
        }

        return $recovery;
    }

    /**
     * Set a new password. Rotates the key pair and issues a NEW recovery key (the new private key
     * must be wrapped for a recovery key the server does not hold) — show it once.
     *
     * @param BackupSecret $current     Current password or recovery key.
     * @param string       $newPassword New password (≥ 12 characters).
     * @return RecoveryKey The new recovery key.
     * @throws WrongBackupSecret When $current does not open the key.
     * @throws BackupKeyError    When the new password is too short.
     */
    public function changePassword(#[\SensitiveParameter] BackupSecret $current, #[\SensitiveParameter] string $newPassword): RecoveryKey
    {
        $this->assertPassword($newPassword);
        $ring = $this->keyRing();

        return $this->rotate($ring, $this->unlock($current), $this->normalize($newPassword));
    }

    /**
     * Replace the recovery key (rotates the key pair; the password stays).
     *
     * @param string $currentPassword Current backup password (needed to wrap the new key pair).
     * @return RecoveryKey The new key, to show once.
     * @throws WrongBackupSecret When the password does not open the key.
     */
    public function regenerateRecoveryKey(#[\SensitiveParameter] string $currentPassword): RecoveryKey
    {
        $ring = $this->keyRing();

        return $this->rotate($ring, $this->unlock(BackupSecret::password($currentPassword)), $this->normalize($currentPassword));
    }

    /**
     * @return KeyRing Public key ring of the site (MAC checked).
     * @throws BackupNotSetUp   When nothing is set up yet.
     * @throws RuntimeException When the stored ring is damaged, altered or cannot be verified.
     */
    public function keyRing(): KeyRing
    {
        if (!$this->isSetUp()) {
            throw new BackupNotSetUp;
        }
        $ring = $this->readRing();
        if ($ring === null) {
            throw new RuntimeException('The stored backup key ring is damaged or cannot be verified.');
        }

        return $ring;
    }

    /**
     * Open the private key with the password or recovery key.
     *
     * With an archive header (signature already checked): the site ring when the key id matches;
     * a previous site key (sealed in the ring) for an archive made before a rotation; then the
     * wraps carried by the header (opening on a new server, or with the secrets the archive was
     * made under).
     *
     * @param BackupSecret              $secret Password or recovery key.
     * @param array<string, mixed>|null $header Signature-checked archive header, or null for the site ring.
     * @return OpenSSLAsymmetricKey Private key, in memory only.
     * @throws WrongBackupSecret When nothing opens.
     * @throws BackupNotSetUp    When no header is given and the site has no usable key ring.
     */
    public function unlock(#[\SensitiveParameter] BackupSecret $secret, ?array $header = null): OpenSSLAsymmetricKey
    {
        $ring = $this->isSetUp() ? $this->readRing() : null;
        if ($header === null) {
            if ($ring === null) {
                throw new BackupNotSetUp;
            }

            return $this->openWith($secret, $ring->keyId, $ring->wraps()) ?? throw new WrongBackupSecret;
        }

        $keyId = is_string($header['key_id'] ?? null) ? $header['key_id'] : '';
        if ($ring !== null && hash_equals($ring->keyId, $keyId)) {
            $key = $this->openWith($secret, $ring->keyId, $ring->wraps());
            if ($key !== null) {
                return $key;
            }
        } elseif ($ring !== null && ($entry = $ring->previousFor($keyId)) !== null) {
            $current = $this->openWith($secret, $ring->keyId, $ring->wraps());
            $der = $current === null ? null : Ecies::open($entry, $current, self::previousContext($keyId), $ring->keyId);
            $key = $der === null ? null : $this->import($der, $keyId);
            if ($key !== null) {
                return $key;
            }
        }

        $wraps = $header['private_key'] ?? null;
        if ($keyId !== '' && is_array($wraps) && ($ring === null || $ring->keyId !== $keyId || $wraps != $ring->wraps())) {
            $key = $this->openWith($secret, $keyId, $wraps);
            if ($key !== null) {
                return $key;
            }
        }

        throw new WrongBackupSecret;
    }

    /**
     * @return string|null 32-byte HMAC key of archive headers, or null when absent / undecryptable.
     */
    public function signingKey(): ?string
    {
        $value = base64_decode((string) $this->store->get(self::SIGNING_KEY), true);

        return ($value !== false && strlen($value) === self::SIGNING_KEY_BYTES) ? $value : null;
    }

    /**
     * New key pair; the old private key and every previous one are sealed for the new public key.
     *
     * @param KeyRing              $ring     Current ring.
     * @param OpenSSLAsymmetricKey $current  Current private key (already unlocked).
     * @param string               $password Normalized password for the new wrap.
     * @return RecoveryKey New recovery key.
     */
    private function rotate(KeyRing $ring, OpenSSLAsymmetricKey $current, #[\SensitiveParameter] string $password): RecoveryKey
    {
        $older = [];
        foreach ($ring->previous as $entry) {
            $id = is_string($entry['key_id'] ?? null) ? $entry['key_id'] : '';
            $der = Ecies::open($entry, $current, self::previousContext($id), $ring->keyId);
            if ($der === null) {
                throw new RuntimeException('A previous backup key cannot be opened; the key ring is damaged.');
            }
            $older[$id] = $der;
        }
        $older[$ring->keyId] = OpenSsl::exportPrivateDer($current);

        $signingKey = $this->signingKey();
        if ($signingKey === null) {
            throw new RuntimeException('The backup signing key is not available.');
        }
        $recovery = RecoveryKey::generate();
        $values = $this->ringValues(OpenSsl::generateKeyPair(), $password, $recovery, $older, $signingKey);

        $this->store->putMany($values, [], [
            self::KEY_ID => $ring->keyId,
            self::RING_MAC => (string) $this->store->get(self::RING_MAC),
        ]);

        return $recovery;
    }

    /**
     * Stored values of a key ring (wraps, previous keys sealed for the new public key, MAC).
     *
     * @param OpenSSLAsymmetricKey  $private    New private key.
     * @param string                $password   Normalized password.
     * @param RecoveryKey           $recovery   Recovery key.
     * @param array<string, string> $older      Previous key id => private DER.
     * @param string                $signingKey Site signing key (MAC key).
     * @return array<string, string>
     */
    private function ringValues(OpenSSLAsymmetricKey $private, #[\SensitiveParameter] string $password, RecoveryKey $recovery, #[\SensitiveParameter] array $older, #[\SensitiveParameter] string $signingKey): array
    {
        $der = OpenSsl::exportPrivateDer($private);
        $publicDer = OpenSsl::publicDer($private);
        $keyId = KeyRing::keyIdOf($publicDer);
        $previous = [];
        foreach ($older as $id => $oldDer) {
            $previous[] = ['key_id' => (string) $id] + Ecies::seal($oldDer, $publicDer, self::previousContext((string) $id), $keyId);
        }

        $values = [
            self::KEY_ID => $keyId,
            self::PUBLIC_KEY => base64_encode($publicDer),
            self::WRAP_PASSWORD => json_encode(KeyWrap::wrapWithPassword($der, $password, $keyId, $this->iterations)),
            self::WRAP_RECOVERY => json_encode(KeyWrap::wrapWithRecovery($der, $recovery->raw(), $keyId)),
            self::PREVIOUS => json_encode($previous),
        ];
        $values[self::RING_MAC] = self::ringMac($values, $signingKey);

        return $values;
    }

    /**
     * MAC over the stored ring, so whoever can write the database cannot swap in their own public
     * key (security review BK-1 #4).
     *
     * @param array<string, string|null> $values     Stored ring values.
     * @param string                     $signingKey Site signing key.
     * @return string Hex HMAC-SHA256.
     */
    private static function ringMac(array $values, #[\SensitiveParameter] string $signingKey): string
    {
        $fields = [];
        foreach ([self::KEY_ID, self::PUBLIC_KEY, self::WRAP_PASSWORD, self::WRAP_RECOVERY, self::PREVIOUS] as $key) {
            $fields[$key] = (string) ($values[$key] ?? '');
        }

        return hash_hmac('sha256', "GP247BK1-RING\n" . CanonicalJson::encode($fields), $signingKey);
    }

    /**
     * @param string $keyId Key id of the sealed previous key.
     * @return string
     */
    private static function previousContext(string $keyId): string
    {
        return 'gp247-backup-prevkey|' . $keyId;
    }

    /**
     * @param BackupSecret         $secret
     * @param string               $keyId
     * @param array<string, mixed> $wraps
     * @return OpenSSLAsymmetricKey|null
     */
    private function openWith(#[\SensitiveParameter] BackupSecret $secret, string $keyId, array $wraps): ?OpenSSLAsymmetricKey
    {
        $der = $this->tryUnwrap($secret, $keyId, $wraps);

        return $der === null ? null : $this->import($der, $keyId);
    }

    /**
     * @param string $der   Private key DER.
     * @param string $keyId Expected key id.
     * @return OpenSSLAsymmetricKey|null
     */
    private function import(#[\SensitiveParameter] string $der, string $keyId): ?OpenSSLAsymmetricKey
    {
        $key = OpenSsl::tryImportPrivateDer($der);

        return ($key !== null && KeyRing::keyIdOf(OpenSsl::publicDer($key)) === $keyId) ? $key : null;
    }

    /**
     * @param BackupSecret         $secret
     * @param string               $keyId
     * @param array<string, mixed> $wraps
     * @return string|null Private key DER.
     */
    private function tryUnwrap(#[\SensitiveParameter] BackupSecret $secret, string $keyId, array $wraps): ?string
    {
        if ($secret->kind === BackupSecret::RECOVERY) {
            $recovery = RecoveryKey::parse($secret->value());
            $wrap = $wraps['recovery'] ?? null;

            return ($recovery !== null && is_array($wrap)) ? KeyWrap::unwrapWithRecovery($wrap, $recovery->raw(), $keyId) : null;
        }

        $wrap = $wraps['password'] ?? null;
        if (!is_array($wrap) || $secret->value() === '') {
            return null;
        }
        foreach (array_unique([$this->normalize($secret->value()), $secret->value()]) as $variant) {
            $der = KeyWrap::unwrapWithPassword($wrap, $variant, $keyId);
            if ($der !== null) {
                return $der;
            }
        }

        return null;
    }

    /**
     * @return KeyRing|null Null when values are missing, inconsistent, or the MAC does not verify.
     */
    private function readRing(): ?KeyRing
    {
        $values = [];
        foreach ([self::KEY_ID, self::PUBLIC_KEY, self::WRAP_PASSWORD, self::WRAP_RECOVERY, self::PREVIOUS, self::RING_MAC] as $key) {
            $values[$key] = (string) $this->store->get($key);
        }
        $signingKey = $this->signingKey();
        if ($signingKey === null || !hash_equals(self::ringMac($values, $signingKey), $values[self::RING_MAC])) {
            return null;
        }

        $publicDer = base64_decode($values[self::PUBLIC_KEY], true);
        $password = json_decode($values[self::WRAP_PASSWORD], true);
        $recovery = json_decode($values[self::WRAP_RECOVERY], true);
        $previous = json_decode($values[self::PREVIOUS], true);
        if ($publicDer === false || $publicDer === '' || KeyRing::keyIdOf($publicDer) !== $values[self::KEY_ID]
            || !is_array($password) || !is_array($recovery) || !is_array($previous) || !array_is_list($previous)) {
            return null;
        }

        return new KeyRing($values[self::KEY_ID], $publicDer, $password, $recovery, $previous);
    }

    /**
     * @param string $password
     * @return void
     * @throws BackupKeyError
     */
    private function assertPassword(#[\SensitiveParameter] string $password): void
    {
        if (mb_strlen($this->normalize($password), 'UTF-8') < self::MIN_PASSWORD_LENGTH) {
            throw new BackupKeyError(BackupKeyError::PASSWORD_TOO_SHORT);
        }
    }

    /**
     * NFC form when intl is available — Vietnamese input methods differ (NFC vs NFD) for the
     * same visible password; unlock also tries the raw form for hosts without intl.
     *
     * @param string $password
     * @return string
     */
    private function normalize(#[\SensitiveParameter] string $password): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($password, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return $password;
    }
}
