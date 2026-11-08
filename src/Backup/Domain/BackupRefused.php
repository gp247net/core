<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * A backup request that cannot start. The reason code is stable so the admin screen and the
 * CLI can explain it in the user's language (BK-4 / BK-5); the message is for logs only.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-selective-backup, US-backup-storage-local
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class BackupRefused extends RuntimeException
{
    public const FEATURE_DISABLED = 'feature_disabled';
    public const SCOPE_EMPTY = 'scope_empty';
    public const GROUP_UNKNOWN = 'group_unknown';
    public const REASON_INVALID = 'reason_invalid';
    public const ALREADY_RUNNING = 'already_running';
    public const DISK_SPACE = 'disk_space';
    public const NEEDS_CONFIRMATION = 'needs_confirmation';
    public const VAULT_UNSAFE = 'vault_unsafe';
    public const NOT_FOUND = 'not_found';
    /** The files of an archive could not be removed (permissions of the backup folder). */
    public const VAULT_DELETE_FAILED = 'vault_delete_failed';

    /**
     * @param string               $reason  One of the constants.
     * @param string               $detail  Short technical detail (no secrets).
     * @param array<string, mixed> $context Data for the caller (estimate, free space…).
     */
    public function __construct(public readonly string $reason, string $detail = '', public readonly array $context = [])
    {
        parent::__construct($reason . ($detail !== '' ? ': ' . $detail : ''));
    }
}
