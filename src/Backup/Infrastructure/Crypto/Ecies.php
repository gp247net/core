<?php

namespace GP247\Core\Backup\Infrastructure\Crypto;

use OpenSSLAsymmetricKey;

/**
 * ECIES over P-256: seals a small secret for a public key, so it can be produced without any
 * password and opened only with the matching private key. Used for the per-archive data key
 * (DEK) and for previous site private keys kept after a key rotation.
 *
 * K = HKDF-SHA256(ECDH(ephemeral, recipient), salt = ephemeral SPKI ‖ recipient SPKI,
 *     info = context); payload sealed with AES-256-GCM, AAD = context ‖ "|" ‖ recipient key id.
 *
 * Sealed shape: {ephemeral, nonce, ct} — base64.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-encryption, US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class Ecies
{
    public const DEK_BYTES = 32;
    private const MAX_PAYLOAD = 4096;

    /**
     * @param string $dek           32-byte data key.
     * @param string $sitePublicDer Site public key (SPKI DER).
     * @param string $archiveId     Archive id the DEK belongs to.
     * @param string $keyId         Site key id.
     * @return array{ephemeral: string, nonce: string, ct: string}
     */
    public static function wrapDek(#[\SensitiveParameter] string $dek, string $sitePublicDer, string $archiveId, string $keyId): array
    {
        return self::seal($dek, $sitePublicDer, self::dekContext($archiveId), $keyId);
    }

    /**
     * @param array<string, mixed> $wrapped     Wrapped DEK from the header (untrusted shape).
     * @param OpenSSLAsymmetricKey $sitePrivate Site private key.
     * @param string               $archiveId   Archive id.
     * @param string               $keyId       Key id recorded in the header.
     * @return string|null DEK, or null when it does not open.
     */
    public static function unwrapDek(array $wrapped, OpenSSLAsymmetricKey $sitePrivate, string $archiveId, string $keyId): ?string
    {
        $dek = self::open($wrapped, $sitePrivate, self::dekContext($archiveId), $keyId);

        return ($dek !== null && strlen($dek) === self::DEK_BYTES) ? $dek : null;
    }

    /**
     * @param string $payload         Secret to seal (≤ 4 KiB).
     * @param string $recipientPublic Recipient SPKI DER.
     * @param string $context         Domain-separation label (what is sealed, for which object).
     * @param string $recipientKeyId  Key id of the recipient.
     * @return array{ephemeral: string, nonce: string, ct: string}
     */
    public static function seal(#[\SensitiveParameter] string $payload, string $recipientPublic, string $context, string $recipientKeyId): array
    {
        if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD) {
            throw new \InvalidArgumentException('ECIES payload size out of range.');
        }
        $ephemeral = OpenSsl::generateKeyPair();
        $ephemeralDer = OpenSsl::publicDer($ephemeral);
        $shared = OpenSsl::ecdh(OpenSsl::importPublicDer($recipientPublic), $ephemeral);
        if ($shared === null) {
            throw new \RuntimeException('ECDH failed while sealing a key.');
        }
        $nonce = random_bytes(OpenSsl::NONCE_BYTES);
        $key = self::kek($shared, $ephemeralDer, $recipientPublic, $context);

        return [
            'ephemeral' => base64_encode($ephemeralDer),
            'nonce' => base64_encode($nonce),
            'ct' => base64_encode(OpenSsl::gcmEncrypt($key, $nonce, $payload, $context . '|' . $recipientKeyId)),
        ];
    }

    /**
     * @param array<string, mixed>  $sealed         Sealed value (untrusted shape).
     * @param OpenSSLAsymmetricKey  $private        Recipient private key.
     * @param string                $context        Same label as when sealing.
     * @param string                $recipientKeyId Same key id as when sealing.
     * @return string|null Payload, or null when it does not open.
     */
    public static function open(array $sealed, OpenSSLAsymmetricKey $private, string $context, string $recipientKeyId): ?string
    {
        $fields = [];
        foreach (['ephemeral', 'nonce', 'ct'] as $field) {
            $bytes = is_string($sealed[$field] ?? null) ? base64_decode($sealed[$field], true) : false;
            if ($bytes === false || $bytes === '') {
                return null;
            }
            $fields[$field] = $bytes;
        }
        $ctLength = strlen($fields['ct']);
        if (strlen($fields['nonce']) !== OpenSsl::NONCE_BYTES || $ctLength <= OpenSsl::TAG_BYTES || $ctLength > self::MAX_PAYLOAD + OpenSsl::TAG_BYTES) {
            return null;
        }
        $ephemeral = OpenSsl::tryImportPublicDer($fields['ephemeral']);
        if ($ephemeral === null) {
            return null;
        }
        $shared = OpenSsl::ecdh($ephemeral, $private);
        if ($shared === null) {
            return null;
        }
        $key = self::kek($shared, $fields['ephemeral'], OpenSsl::publicDer($private), $context);

        return OpenSsl::gcmDecrypt($key, $fields['nonce'], $fields['ct'], $context . '|' . $recipientKeyId);
    }

    /**
     * @param string $archiveId
     * @return string
     */
    private static function dekContext(string $archiveId): string
    {
        return 'gp247-backup-dek|' . $archiveId;
    }

    /**
     * @param string $shared       ECDH output.
     * @param string $ephemeralDer Ephemeral SPKI DER.
     * @param string $recipientDer Recipient SPKI DER.
     * @param string $context      Label.
     * @return string Key-encryption key.
     */
    private static function kek(#[\SensitiveParameter] string $shared, string $ephemeralDer, string $recipientDer, string $context): string
    {
        return hash_hkdf('sha256', $shared, OpenSsl::KEY_BYTES, $context, $ephemeralDer . $recipientDer);
    }
}
