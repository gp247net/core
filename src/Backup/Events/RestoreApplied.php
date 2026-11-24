<?php

namespace GP247\Core\Backup\Events;

/**
 * Tables of the site now hold data from a backup (or, after an undo, data from before a
 * restore). Sent when a table-replacing restore ends — inside the transaction that ends it —,
 * when it ended with errors after the tables were swapped, and before an undo puts the old
 * tables back. Data written after $dataAsOf is not in those tables any more: a listener that
 * keeps its own record (the personal data erasure ledger) applies it again.
 *
 * A listener may return an integer (how many records it will apply again); the restore result
 * shows the sum.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-restore-reapply
 * @aidlc-adr personal-data-registry_restore-reapply
 */
final class RestoreApplied
{
    public const PHASE_DONE = 'done';
    public const PHASE_FAILED_AFTER_SWAP = 'failed_after_swap';
    public const PHASE_UNDO = 'undo';

    public const ORIGIN_SITE = 'site';
    public const ORIGIN_OTHER = 'other';

    /**
     * @param string       $level    Restore level (full, replace, config…), or "undo".
     * @param string       $dataAsOf ISO 8601 time the data now in the tables is from; "" = unknown
     *                               (an archive of another site, or one that did not record it).
     * @param list<string> $tables   Real table names that were replaced.
     * @param string       $phase    One of the PHASE_* constants.
     * @param string       $origin   ORIGIN_SITE when the data is this site's own (ids can be trusted).
     */
    public function __construct(
        public readonly string $level,
        public readonly string $dataAsOf,
        public readonly array $tables,
        public readonly string $phase,
        public readonly string $origin = self::ORIGIN_SITE,
    ) {
    }

    /**
     * Call every listener on its own (one failing or answering false never stops the others) and
     * add up their answers. A failure never breaks the restore: it is reported by exception class
     * only — a message may carry SQL with personal data — and the total becomes unknown (null).
     *
     * @param self $event
     * @return int|null Records the listeners will apply again; null when one of them failed.
     */
    public static function dispatchAndCount(self $event): ?int
    {
        $total = 0;
        $failed = false;
        foreach (app('events')->getListeners(self::class) as $listener) {
            try {
                $answer = $listener(self::class, [$event]);
                $total += is_int($answer) ? $answer : 0;
            } catch (\Throwable $e) {
                $failed = true;
                if (function_exists('gp247_report')) {
                    gp247_report('RestoreApplied listener failed: ' . $e::class);
                }
            }
        }

        return $failed ? null : $total;
    }
}
