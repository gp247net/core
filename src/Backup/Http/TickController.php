<?php

namespace GP247\Core\Backup\Http;

use GP247\Core\Backup\Application\BackupAccess;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\DashboardStatus;
use GP247\Core\Backup\Application\JobRunner;
use GP247\Core\Backup\Domain\Archive;
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
 * @aidlc-story US-backup-admin-dashboard, US-backup-engine-resumable-run
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
