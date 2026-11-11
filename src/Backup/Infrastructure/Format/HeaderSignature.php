<?php

namespace GP247\Core\Backup\Infrastructure\Format;

/**
 * HMAC-SHA256 of the archive header with the site signing key. The header carries the
 * manifest hash, and the manifest carries every part / segment hash, so the signature covers
 * the whole archive. There is no flag to skip it.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-integrity-manifest
 * @aidlc-adr backup-engine_tamper-evidence
 */
final class HeaderSignature
{
    private const DOMAIN = "GP247BK1-HMAC\n";

    /**
     * @param array<string, mixed> $header     Header (an existing "hmac" field is ignored).
     * @param string               $signingKey 32-byte site signing key.
     * @return string Hex HMAC.
     */
    public static function sign(array $header, #[\SensitiveParameter] string $signingKey): string
    {
        unset($header['hmac']);

        return hash_hmac('sha256', self::DOMAIN . CanonicalJson::encode($header), $signingKey);
    }

    /**
     * @param array<string, mixed> $header     Header with its "hmac" field.
     * @param string               $signingKey 32-byte site signing key.
     * @return bool
     */
    public static function verify(array $header, #[\SensitiveParameter] string $signingKey): bool
    {
        $mac = $header['hmac'] ?? null;
        if (!is_string($mac)) {
            return false;
        }
        try {
            return hash_equals(self::sign($header, $signingKey), $mac);
        } catch (\JsonException) {
            // A value JSON cannot encode (INF from "1e400") is simply not a valid signature.
            return false;
        }
    }
}
