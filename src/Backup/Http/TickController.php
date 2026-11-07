<?php

namespace GP247\Core\Backup\Http;

use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\BackupLog;
use GP247\Core\Backup\Application\BackupService;
use GP247\Core\Backup\Application\DashboardStatus;
use GP247\Core\Backup\Application\JobRunner;
use GP247\Core\Backup\Application\ScheduleClock;
use GP247\Core\Backup\Application\ScheduleSettings;
use GP247\Core\Backup\Application\StaleAlert;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\BackupNotSetUp;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Storage\VaultExposure;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Runs ONE slice of the job in progress for the open backup screen (the browser calls again
 * while `more` is true) — how a backup or restore started from the web finishes on a host
 * without cron or CLI. Backup / check jobs need the create right (route
 * `admin.backup.tick`), restore jobs the restore right (`admin.backup.restore_tick`).
 * The "when an admin visits" schedule beacon (BK-7) calls the same endpoint.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard, US-backup-engine-resumable-run, US-backup-admin-schedule
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
class TickController
{
    public const ROUTE_CREATE = 'admin.backup.tick';
    public const ROUTE_RESTORE = 'admin.backup.restore_tick';

    /**
     * @param Request $request
     * @return JsonResponse {more: bool, next_in: int, status: string, job: array|null}
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (!BackupFeature::enabled()) {
            return response()->json(['more' => false, 'next_in' => 0, 'status' => 'disabled', 'job' => null]);
        }
        $restore = $request->route()?->getName() === self::ROUTE_RESTORE;
        if (!BackupAccess::allows($restore ? BackupAccess::RESTORE : BackupAccess::CREATE)) {
            BackupAudit::record('tick', BackupAudit::DENIED, ['right' => $restore ? BackupAccess::RESTORE : BackupAccess::CREATE]);

            return response()->json(['more' => false, 'next_in' => 0, 'status' => 'denied', 'job' => null], 403);
        }
        $job = $this->jobFor($restore);
        if ($job === null && !$restore) {
            // BK-7 "when an admin visits": nothing in progress ⇒ start the scheduled backup if due.
            $job = $this->startScheduled();
            (new StaleAlert)->sendIfDue();
            // A background request: the place where a site without a command line gets its daily
            // "backup folder reachable over the web" probe (System alerts only reads the result).
            VaultExposure::refreshIfStale(app(LocalVault::class));
        }
        if ($job === null) {
            return response()->json(['more' => false, 'next_in' => 0, 'status' => 'idle', 'job' => null]);
        }
        $result = (new JobRunner)->runSlice((int) $job->id);
        $next = $this->jobFor($restore);

        return response()->json([
            'more' => $next !== null,
            'next_in' => $result['status'] === 'busy' ? 3 : 1,
            'status' => $result['status'],
            'job' => (new DashboardStatus)->activeJob(),
        ]);
    }

    /**
     * Start the scheduled backup when due ("when an admin visits" mode). Two tabs or two admins
     * cannot start two: BackupService::create() serialises on a row lock and refuses while a
     * job that writes is active.
     *
     * @return object|null The new job, null when not due or refused.
     *
     * @aidlc-story US-backup-admin-schedule
     */
    private function startScheduled(): ?object
    {
        $clock = new ScheduleClock;
        if (!$clock->due(true)) {
            return null;
        }
        $clock->markTried();
        try {
            // confirm_large: the administrator chose the schedule; nobody is there to answer the
            // "large backup from the web" question, and the runner works in slices anyway.
            $created = (new BackupService)->create(
                ScheduleSettings::scope(ScheduleSettings::read()['kind']),
                Archive::REASON_SCHEDULED,
                ['via' => BackupService::VIA_WEB, 'confirm_large' => true, 'created_by' => 'schedule'],
            );
        } catch (BackupRefused|BackupNotSetUp $e) {
            // e.g. a job started meanwhile in another tab, or the disk is full — tried again in an hour.
            BackupLog::failure('Scheduled backup not started: ' . ($e instanceof BackupRefused ? $e->reason : 'not_set_up'));

            return null;
        }
        BackupAudit::record('create', BackupAudit::OK, ['archive_id' => $created['archive_id'], 'job_id' => $created['job_id'], 'reason' => Archive::REASON_SCHEDULED]);

        return (new JobRepository)->find((int) $created['job_id']);
    }

    /**
     * @param bool $restore
     * @return object|null Oldest active job of the kind this route may run.
     */
    private function jobFor(bool $restore): ?object
    {
        // BK-6: the copy to the external destination follows the verification on the same screen.
        $types = $restore ? [Archive::JOB_RESTORE, Archive::JOB_IMPORT] : [Archive::JOB_BACKUP, Archive::JOB_VERIFY, Archive::JOB_UPLOAD];
        foreach ($types as $type) {
            $jobs = (new JobRepository)->activeOfType($type);
            if ($jobs !== []) {
                return $jobs[0];
            }
        }

        return null;
    }
}
