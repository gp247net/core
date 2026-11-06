<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Infrastructure\Site\MaintenanceGuard;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Backup\Infrastructure\Store\RestoreLedger;

/**
 * Ends what a restore left behind when nobody runs it any more — shared hosts have no worker and
 * often no command line:
 *
 *  - a restore job whose data key window (one hour) is over and that no slice holds: ended as
 *    failed (its data key leaves the database), the site put back as far as it got;
 *  - an undo that died in the middle: ended, its tables kept for inspection;
 *  - a maintenance mode turned on by a restore while no restore job is active any more: off.
 *
 * Runs from the idle runner and, while the site is in maintenance, early in every request
 * ({@see onBoot()}) — the only request that still reaches PHP then.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-full-database
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class RestoreWatchdog
{
    private const LEASE_SECONDS = 120;

    private JobRepository $jobs;

    /**
     * @param JobRepository|null $jobs
     */
    public function __construct(?JobRepository $jobs = null)
    {
        $this->jobs = $jobs ?? new JobRepository;
    }

    /**
     * Cheap guard for the boot hook: only acts while a restore's maintenance mode is on.
     *
     * @return void
     */
    public static function onBoot(): void
    {
        try {
            if (!app()->maintenanceMode()->active() || app(MaintenanceGuard::class)->restoreUntil() === null) {
                return;
            }
            (new self)->sweep();
        } catch (\Throwable $e) {
            BackupLog::failure('Restore watchdog failed: ' . class_basename($e));
        }
    }

    /**
     * @return array{ended: list<int>, maintenance_released: bool}
     */
    public function sweep(): array
    {
        $ended = [];
        foreach ($this->jobs->activeOfType(Archive::JOB_RESTORE) as $job) {
            if ($this->endIfStale($job)) {
                $ended[] = (int) $job->id;
            }
        }
        foreach ($this->jobs->activeOfType(Archive::JOB_IMPORT) as $job) {
            if ($this->endImportIfStale($job)) {
                $ended[] = (int) $job->id;
            }
        }

        $released = false;
        $guard = app(MaintenanceGuard::class);
        if ($guard->restoreUntil() !== null && $this->jobs->activeOfType(Archive::JOB_RESTORE) === []) {
            $guard->deactivate();
            $released = true;
            BackupLog::failure('Maintenance mode left by a restore was turned off.');
        }

        return ['ended' => $ended, 'maintenance_released' => $released];
    }

    /**
     * An import nobody drives any more (the tab was closed) would block every backup and restore:
     * once its data key outlived its window it is ended and its unpacked copy removed.
     *
     * @param object $job Active import job.
     * @return bool It was ended.
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    private function endImportIfStale(object $job): bool
    {
        try {
            $cursor = $this->jobs->cursor($job);
        } catch (\Throwable $e) {
            $cursor = [];
        }
        if ($cursor !== [] && now()->getTimestamp() - (int) ($cursor['started'] ?? 0) <= RestoreStep::KEY_TTL_SECONDS) {
            return false;
        }
        $owner = bin2hex(random_bytes(16));
        if (!$this->jobs->acquire((int) $job->id, $owner, now()->addSeconds(self::LEASE_SECONDS))
            || !$this->jobs->finish((int) $job->id, $owner, Archive::JOB_FAILED, 'import: key_expired')) {
            return false;
        }
        (new ImportStep($this->jobs, new \GP247\Core\Backup\Infrastructure\Store\ArchiveRepository, new KeyService, app(LocalVault::class)))->abort($cursor);

        return true;
    }

    /**
     * @param object $job Active restore job.
     * @return bool It was ended.
     */
    private function endIfStale(object $job): bool
    {
        try {
            $cursor = $this->jobs->cursor($job);
        } catch (\Throwable $e) {
            $cursor = [];
        }
        $phase = $cursor['phase'] ?? null;
        $keyExpired = isset($cursor['dek']) && now()->getTimestamp() - (int) ($cursor['started'] ?? 0) > RestoreStep::KEY_TTL_SECONDS;
        if (!$keyExpired && $phase !== RestoreStep::UNDO_PHASE && $cursor !== []) {
            return false;
        }
        // Only a job no slice holds: acquire() fails while a lease is alive (a running undo holds one).
        $owner = bin2hex(random_bytes(16));
        if (!$this->jobs->acquire((int) $job->id, $owner, now()->addSeconds(self::LEASE_SECONDS))) {
            return false;
        }
        $reason = $phase === RestoreStep::UNDO_PHASE ? 'undo_interrupted' : 'key_expired';
        if (!$this->jobs->finish((int) $job->id, $owner, Archive::JOB_FAILED, 'restore: ' . $reason)) {
            return false;
        }
        $ledger = new RestoreLedger;
        if ($phase === RestoreStep::UNDO_PHASE) {
            $ledger->quarantine(array_values(array_map('strval', (array) ($cursor['tables'] ?? []))));
        }
        (new RestoreStep($this->jobs, new KeyService, app(LocalVault::class), $ledger))->abort($job, $cursor, $reason);

        return true;
    }
}
