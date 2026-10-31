<?php

namespace GP247\Core\Api\Sync;

/**
 * Opaque cursor of the changed-since mode of admin lists: the `updated_at` and the key
 * of the last row of a page, base64url without padding. A cursor that does not decode
 * to exactly that shape is refused — it never reaches a query as anything but two bound
 * values.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-changed-since-filter
 * @aidlc-adr api-sync_keyset-changed-since
 */
final class ChangeCursor
{
    /** Longest cursor accepted (a timestamp, a separator and a key). */
    private const MAX_LENGTH = 200;

    /**
     * @param string $updatedAt Raw `Y-m-d H:i:s` value of the row.
     * @param string $key       Primary key of the row.
     * @return string
     */
    public static function encode(string $updatedAt, string $key): string
    {
        return rtrim(strtr(base64_encode($updatedAt . '|' . $key), '+/', '-_'), '=');
    }

    /**
     * @param string $cursor
     * @return array{0: string, 1: string}|null [updated_at, key], or null when malformed.
     */
    public static function decode(string $cursor): ?array
    {
        if ($cursor === '' || strlen($cursor) > self::MAX_LENGTH || !preg_match('/^[A-Za-z0-9_-]+$/', $cursor)) {
            return null;
        }
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || substr_count($raw, '|') < 1) {
            return null;
        }
        [$updatedAt, $key] = explode('|', $raw, 2);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updatedAt) || $key === '' || strlen($key) > 100) {
            return null;
        }

        return [$updatedAt, $key];
    }
}
