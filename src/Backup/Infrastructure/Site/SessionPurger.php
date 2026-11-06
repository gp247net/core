<?php

namespace GP247\Core\Backup\Infrastructure\Site;

use Illuminate\Support\Facades\DB;

/**
 * Ends every web session of the site (admins and customers) — post-incident mode. Supported:
 * the "database" driver (rows of the session table) and the "file" driver (the session files
 * Laravel wrote: 40-character names in the session folder, nothing else is touched). Other
 * drivers (redis, memcached, cookie…) are reported as "manual" with the reason.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-post-incident-mode
 */
final class SessionPurger
{
    /**
     * @return array{status: string, count: int, detail: string} status ok | manual.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public function purge(): array
    {
        $driver = (string) config('session.driver');

        return match ($driver) {
            'database' => $this->database(),
            'file' => $this->files((string) config('session.files')),
            'array' => ['status' => 'ok', 'count' => 0, 'detail' => 'array'],
            default => ['status' => 'manual', 'count' => 0, 'detail' => 'driver ' . preg_replace('/[^a-z0-9_-]/i', '', $driver)],
        };
    }

    /**
     * @return array{status: string, count: int, detail: string}
     */
    private function database(): array
    {
        $connection = config('session.connection') ?: config('database.default');
        $table = (string) config('session.table', 'sessions');
        $count = DB::connection($connection)->table($table)->delete();

        return ['status' => 'ok', 'count' => (int) $count, 'detail' => 'database'];
    }

    /**
     * @param string $dir
     * @return array{status: string, count: int, detail: string}
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    public function files(string $dir): array
    {
        if ($dir === '' || !is_dir($dir)) {
            return ['status' => 'ok', 'count' => 0, 'detail' => 'file'];
        }
        $count = 0;
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            // Laravel session ids are 40 alphanumeric characters; .gitignore and the like stay.
            if (preg_match('/^[A-Za-z0-9]{40}$/D', $name) === 1 && is_file($path) && !is_link($path) && @unlink($path)) {
                $count++;
            }
        }

        return ['status' => 'ok', 'count' => $count, 'detail' => 'file'];
    }
}
