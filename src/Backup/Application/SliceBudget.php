<?php

namespace GP247\Core\Backup\Application;

/**
 * Time budget of one web slice: min(20 s, max_execution_time − 10 s); 20 s when the limit is
 * 0 (unlimited) or unreadable — a shared host kills the request at its own limit, and the slice
 * must have ended cleanly (cursor saved, lease released) well before that.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class SliceBudget
{
    public const MAX_SECONDS = 20.0;
    private const SAFETY_SECONDS = 10;

    /**
     * @param string|false|null $maxExecutionTime ini value (null = read ini_get).
     * @return float Seconds, at least 1.
     */
    public static function seconds(string|false|null $maxExecutionTime = null): float
    {
        $raw = $maxExecutionTime ?? ini_get('max_execution_time');
        $limit = is_string($raw) && is_numeric($raw) ? (int) $raw : 0;
        if ($limit <= 0) {
            return self::MAX_SECONDS;
        }

        return max(1.0, min(self::MAX_SECONDS, (float) ($limit - self::SAFETY_SECONDS)));
    }
}
