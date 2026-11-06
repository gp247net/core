<?php

namespace GP247\Core\Backup\Domain;

/**
 * Vocabulary of a backup archive and of the jobs that build it: one source for the service,
 * the repositories, the runner and the tests.
 *
 * Archive status runs running → verifying → ok | broken | cancelled; only "ok" is a
 * successful backup. Job status runs pending → running → done | failed | cancelled.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-quick-snapshot, US-backup-engine-selective-backup, US-backup-engine-full-backup
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class Archive
{
    public const KIND_SNAPSHOT = 'snapshot';
    public const KIND_SELECTIVE = 'selective';
    public const KIND_FULL = 'full';
    public const KIND_IMPORTED = 'imported';

    public const REASON_MANUAL = 'manual';
    public const REASON_SCHEDULED = 'scheduled';
    public const REASON_PRE_CHANGE = 'pre_change:';
    /** Archive brought in from a file or an upload (BK-5); its date is the original one. */
    public const REASON_IMPORT = 'import';

    public const STATUS_RUNNING = 'running';
    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_OK = 'ok';
    public const STATUS_BROKEN = 'broken';
    public const STATUS_CANCELLED = 'cancelled';

    public const CONSISTENT = 'consistent';
    public const MULTI_SLICE = 'multi_slice';

    public const OFFSITE_NONE = 'none';
    public const OFFSITE_PENDING = 'pending';
    public const OFFSITE_DONE = 'done';
    public const OFFSITE_FAILED = 'failed';

    public const JOB_BACKUP = 'backup';
    public const JOB_VERIFY = 'verify';
    public const JOB_RESTORE = 'restore';
    public const JOB_IMPORT = 'import';
    /** Copy of a verified archive to the external destination (BK-6); writes nothing on the site. */
    public const JOB_UPLOAD = 'upload';

    /** @var list<string> Jobs that write site data or the catalogue: only one may be active at a time. */
    public const WRITE_JOBS = [self::JOB_BACKUP, self::JOB_RESTORE, self::JOB_IMPORT];

    public const JOB_PENDING = 'pending';
    public const JOB_RUNNING = 'running';
    public const JOB_DONE = 'done';
    public const JOB_FAILED = 'failed';
    public const JOB_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const JOB_ACTIVE = [self::JOB_PENDING, self::JOB_RUNNING];

    /**
     * @param string $reason
     * @return bool A reason the catalogue accepts: manual, scheduled, pre_change:<operation>.
     */
    public static function validReason(string $reason): bool
    {
        return in_array($reason, [self::REASON_MANUAL, self::REASON_SCHEDULED], true)
            || preg_match('/^pre_change:[A-Za-z0-9_.:\/-]{1,80}$/D', $reason) === 1;
    }

    /**
     * @param string $reason
     * @return bool Snapshot taken before a risky change (kept under its own count).
     */
    public static function isPreChange(string $reason): bool
    {
        return str_starts_with($reason, self::REASON_PRE_CHANGE);
    }
}
