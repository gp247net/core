<?php

namespace GP247\Core\Backup\Infrastructure\Format;

/**
 * Deterministic JSON for signing: object keys sorted recursively, no escaped slashes or
 * Unicode, so the same header always gives the same bytes.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-integrity-manifest
 * @aidlc-adr backup-engine_tamper-evidence
 */
final class CanonicalJson
{
    /**
     * @param mixed $value JSON-compatible value.
     * @return string
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = array_map([self::class, 'sort'], $value);
        if (!array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
