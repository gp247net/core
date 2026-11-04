<?php

namespace GP247\Core\Backup\Infrastructure\Crypto;

/**
 * Wraps the site private key (PKCS#8 DER) with the backup password (PBKDF2-HMAC-SHA256) or
 * the recovery key (HKDF-SHA256), then AES-256-GCM. The KDF parameters are stored with the
 * wrap and authenticated as AAD, so they can be raised later without breaking old wraps.
 *
 * Wrap shape: {v:1, kdf, iter?, salt, nonce, ct} — binary fields base64.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup, US-backup-engine-encryption
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class KeyWrap
{
    public const DEFAULT_ITERATIONS = 600_000;
    public const MAX_ITERATIONS = 10_000_000;
    private const KDF_PASSWORD = 'pbkdf2-sha256';
    private const KDF_RECOVERY = 'hkdf-sha256';
    private const SALT_BYTES = 16;
    private const MAX_CT_BYTES = 4096;

    /**
     * @param string $privateDer Private key DER.
     * @param string $password   Backup password (already normalized by the caller).
     * @param string $keyId      Site key id.
     * @param int    $iterations PBKDF2 iterations.
     * @return array<string, int|string>
     */
    public static function wrapWithPassword(#[\SensitiveParameter] string $privateDer, #[\SensitiveParameter] string $password, string $keyId, int $iterations): array
    {
        $salt = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(OpenSsl::NONCE_BYTES);
        $key = hash_pbkdf2('sha256', $password, $salt, $iterations, OpenSsl::KEY_BYTES, true);

        return [
            'v' => 1,
            'kdf' => self::KDF_PASSWORD,
            'iter' => $iterations,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'ct' => base64_encode(OpenSsl::gcmEncrypt($key, $nonce, $privateDer, self::passwordAad($keyId, $iterations))),
        ];
    }

    /**
     * @param array<string, mixed> $wrap     Stored wrap (untrusted shape).
     * @param string               $password Candidate password.
     * @param string               $keyId    Site key id.
     * @return string|null Private key DER, or null when it does not open.
     */
    public static function unwrapWithPassword(array $wrap, #[\SensitiveParameter] string $password, string $keyId): ?string
    {
        $parts = self::parse($wrap, self::KDF_PASSWORD);
        if ($parts === null) {
            return null;
        }
        $iter = $wrap['iter'] ?? null;
        if (!is_int($iter) || $iter < 1 || $iter > self::MAX_ITERATIONS) {
            return null;
        }
        $key = hash_pbkdf2('sha256', $password, $parts['salt'], $iter, OpenSsl::KEY_BYTES, true);

        return OpenSsl::gcmDecrypt($key, $parts['nonce'], $parts['ct'], self::passwordAad($keyId, $iter));
    }

    /**
     * @param string $privateDer  Private key DER.
     * @param string $recoveryRaw 32 raw bytes of the recovery key.
     * @param string $keyId       Site key id.
     * @return array<string, int|string>
     */
    public static function wrapWithRecovery(#[\SensitiveParameter] string $privateDer, #[\SensitiveParameter] string $recoveryRaw, string $keyId): array
    {
        $salt = random_bytes(self::SALT_BYTES);
        $nonce = random_bytes(OpenSsl::NONCE_BYTES);
        $key = hash_hkdf('sha256', $recoveryRaw, OpenSsl::KEY_BYTES, self::recoveryAad($keyId), $salt);

        return [
            'v' => 1,
            'kdf' => self::KDF_RECOVERY,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'ct' => base64_encode(OpenSsl::gcmEncrypt($key, $nonce, $privateDer, self::recoveryAad($keyId))),
        ];
    }

    /**
     * @param array<string, mixed> $wrap        Stored wrap (untrusted shape).
     * @param string               $recoveryRaw Candidate recovery key bytes.
     * @param string               $keyId       Site key id.
     * @return string|null Private key DER, or null when it does not open.
     */
    public static function unwrapWithRecovery(array $wrap, #[\SensitiveParameter] string $recoveryRaw, string $keyId): ?string
    {
        $parts = self::parse($wrap, self::KDF_RECOVERY);
        if ($parts === null || $recoveryRaw === '') {
            return null;
        }
        $key = hash_hkdf('sha256', $recoveryRaw, OpenSsl::KEY_BYTES, self::recoveryAad($keyId), $parts['salt']);

        return OpenSsl::gcmDecrypt($key, $parts['nonce'], $parts['ct'], self::recoveryAad($keyId));
    }

    /**
     * Strict shape check of a wrap; decodes the binary fields.
     *
     * @param array<string, mixed> $wrap
     * @param string               $kdf Expected KDF name.
     * @return array{salt: string, nonce: string, ct: string}|null
     */
    private static function parse(array $wrap, string $kdf): ?array
    {
        if (($wrap['v'] ?? null) !== 1 || ($wrap['kdf'] ?? null) !== $kdf) {
            return null;
        }
        $out = [];
        foreach (['salt' => self::SALT_BYTES, 'nonce' => OpenSsl::NONCE_BYTES, 'ct' => null] as $field => $length) {
            $value = $wrap[$field] ?? null;
            $bytes = is_string($value) ? base64_decode($value, true) : false;
            if ($bytes === false
                || ($length !== null && strlen($bytes) !== $length)
                || ($length === null && (strlen($bytes) <= OpenSsl::TAG_BYTES || strlen($bytes) > self::MAX_CT_BYTES))) {
                return null;
            }
            $out[$field] = $bytes;
        }

        return $out;
    }

    /**
     * @param string $keyId
     * @param int    $iterations
     * @return string
     */
    private static function passwordAad(string $keyId, int $iterations): string
    {
        return 'gp247-backup-wrap|password|' . $keyId . '|' . $iterations;
    }

    /**
     * @param string $keyId
     * @return string
     */
    private static function recoveryAad(string $keyId): string
    {
        return 'gp247-backup-wrap|recovery|' . $keyId;
    }
}
