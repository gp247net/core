<?php

namespace GP247\Core\Backup\Application;

use Illuminate\Support\Facades\Artisan;

/**
 * The data update that follows the restore of an archive older than the site
 * (US-backup-restore-version-compatibility): `gp247:update` run in-process — no `exec`, so it
 * works on shared hosts — without asking the "before change" hooks (the restore already made
 * its own safety copy). Bound in the container so tests replace it.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-version-compatibility
 * @aidlc-adr backup-restore_shadow-table-swap
 */
class DataUpdater
{
    /**
     * @return array{ok: bool, code: int, error: string|null} Exit code and the envelope's error code.
     */
    public function run(): array
    {
        // WHY: half a data update is worse than a slow one — let it finish when the host allows
        // (some hosts forbid both calls; then "interrupted" is reported and the CLI is advised).
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        try {
            $code = Artisan::call('gp247:update', ['--skip-pre-change' => true, '--json' => true]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => -1, 'error' => class_basename($e)];
        }
        $envelope = json_decode(trim(Artisan::output()), true);
        $error = is_array($envelope) && is_string($envelope['error']['code'] ?? null) ? $envelope['error']['code'] : null;

        return ['ok' => $code === 0, 'code' => $code, 'error' => $code === 0 ? null : $error];
    }
}
