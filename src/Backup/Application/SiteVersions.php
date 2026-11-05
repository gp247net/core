<?php

namespace GP247\Core\Backup\Application;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;

/**
 * Versions of the running site, written into every archive (meta.packages, meta.migrations) and
 * compared with them before a restore (US-backup-restore-version-compatibility).
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-version-compatibility, US-backup-engine-safe-format
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class SiteVersions
{
    /**
     * @return array<string, string> Package => version.
     */
    public static function packages(): array
    {
        $packages = ['gp247/core' => (string) config('gp247.core')];
        foreach (['gp247/front', 'gp247/shop', 'gp247/mcp'] as $package) {
            try {
                if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                    $packages[$package] = (string) InstalledVersions::getPrettyVersion($package);
                }
            } catch (\Throwable $e) {
                // Version unknown: the migration list still decides.
            }
        }

        return $packages;
    }

    /**
     * @return list<string> Migrations run on this site (names only), empty when unreadable.
     */
    public static function migrations(): array
    {
        try {
            return DB::connection(GP247_DB_CONNECTION)->table(self::migrationsTable())->orderBy('id')->pluck('migration')->map(fn ($m) => (string) $m)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return string Real name of Laravel's migration table.
     */
    public static function migrationsTable(): string
    {
        $setting = config('database.migrations');

        return (string) (is_array($setting) ? ($setting['table'] ?? 'migrations') : ($setting ?: 'migrations'));
    }
}
