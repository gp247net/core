<?php

namespace GP247\Core\Backup\Application;

use Illuminate\Support\Facades\Log;

/**
 * Failure reports of the backup area. Never throws: a broken log channel must not turn a
 * reported failure into a second one. Messages carry ids and reason codes only, no data.
 * Email notification of failures comes with the admin screens (US-backup-admin-notifications).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-integrity-manifest
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class BackupLog
{
    /**
     * @param string $message
     * @return void
     */
    public static function failure(string $message): void
    {
        try {
            Log::warning('[gp247-backup] ' . $message);
            if (function_exists('gp247_report')) {
                gp247_report('[gp247-backup] ' . $message);
            }
        } catch (\Throwable $e) {
            // Reporting is best effort.
        }
    }
}
