<?php

namespace GP247\Core\Backup\Application;

use RuntimeException;

/**
 * A restore job that must stop for good (no retry): the site is put back and the job fails with
 * the reason code (key_expired, row_count, row_count_after_swap, swap_state…).
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-full-database
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class RestoreAborted extends RuntimeException
{
    /**
     * @param string $reason Stable reason code.
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Restore aborted: ' . $reason);
    }
}
