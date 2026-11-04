<?php

namespace GP247\Core\Backup\Domain;

/**
 * Public half of the site backup keys: key id, public key, the two wrapped copies of the
 * current private key (by backup password, by recovery key), and the previous private keys
 * sealed for the current public key (kept after each rotation so older archives on this site
 * still open with the current secrets). Never holds a private key in clear.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup, US-backup-engine-encryption
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class KeyRing
{
    /**
     * @param string                           $keyId        Hex of the first 16 bytes of SHA-256(public SPKI DER).
     * @param string                           $publicDer    Site public key, SPKI DER.
     * @param array<string, mixed>             $passwordWrap Private key wrapped with the backup password.
     * @param array<string, mixed>             $recoveryWrap Private key wrapped with the recovery key.
     * @param list<array<string, mixed>>       $previous     Previous private keys: {key_id, ephemeral, nonce, ct} sealed for $publicDer.
     */
    public function __construct(
        public readonly string $keyId,
        public readonly string $publicDer,
        public readonly array $passwordWrap,
        public readonly array $recoveryWrap,
        public readonly array $previous = [],
    ) {
    }

    /**
     * Key id of a public key.
     *
     * @param string $publicDer SPKI DER.
     * @return string 32 hex characters.
     */
    public static function keyIdOf(string $publicDer): string
    {
        return bin2hex(substr(hash('sha256', $publicDer, true), 0, 16));
    }

    /**
     * The two wraps, as copied into every archive header.
     *
     * @return array{password: array<string, mixed>, recovery: array<string, mixed>}
     */
    public function wraps(): array
    {
        return ['password' => $this->passwordWrap, 'recovery' => $this->recoveryWrap];
    }

    /**
     * @param string $keyId Key id of an older archive.
     * @return array<string, mixed>|null Sealed previous private key for that id.
     */
    public function previousFor(string $keyId): ?array
    {
        foreach ($this->previous as $entry) {
            if (is_array($entry) && ($entry['key_id'] ?? null) === $keyId) {
                return $entry;
            }
        }

        return null;
    }
}
