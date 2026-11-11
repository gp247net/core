<?php

namespace GP247\Core\Backup\Application;

/**
 * Where a migration comes from, so that "this backup is newer than the site" can say what to do:
 * update a package (gp247/core, gp247/front, gp247/shop, gp247/mcp), install an extension, or —
 * for a migration found nowhere on this site — "unknown" (typically an extension not installed
 * here). Only file names in the migration folders of the installed packages, the extensions
 * present on disk and the application are looked at; nothing is read from the archive.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-version-compatibility
 */
final class MigrationSources
{
    public const UNKNOWN = 'unknown';

    /** Migration folders inside a package's src/ (the core and mcp use the first, front and shop the second). */
    private const PACKAGE_FOLDERS = ['Database/Migrations', 'Admin/Database/Migrations'];

    /**
     * @param list<string> $migrations Migration names.
     * @return array<string, list<string>> Source => its migrations, in the order given; UNKNOWN last.
     */
    public static function group(array $migrations): array
    {
        if ($migrations === []) {
            return [];
        }
        $index = self::index();
        $out = [];
        foreach ($migrations as $migration) {
            $out[$index[$migration] ?? self::UNKNOWN][] = $migration;
        }
        if (isset($out[self::UNKNOWN])) {
            $unknown = $out[self::UNKNOWN];
            unset($out[self::UNKNOWN]);
            $out[self::UNKNOWN] = $unknown;
        }

        return $out;
    }

    /**
     * @return array<string, string> Migration name => source.
     */
    private static function index(): array
    {
        $index = [];
        // WHY by folder, not by class: the core never names a class of another package (module
        // isolation) — every installed gp247/* package sits next to the core in the vendor folder.
        $vendor = dirname(__DIR__, 4);
        foreach (glob($vendor . '/*', GLOB_ONLYDIR) ?: [] as $package) {
            foreach (self::PACKAGE_FOLDERS as $folder) {
                self::add($index, $package . '/src/' . $folder, 'gp247/' . basename($package));
            }
        }
        foreach (['Plugins', 'Templates'] as $type) {
            foreach (glob(app_path('GP247/' . $type . '/*'), GLOB_ONLYDIR) ?: [] as $extension) {
                self::add($index, $extension . '/DB/migrations', $type . '/' . basename($extension));
            }
        }
        self::add($index, database_path('migrations'), 'app');

        return $index;
    }

    /**
     * @param array<string, string> $index
     * @param string                $dir
     * @param string                $source
     * @return void
     */
    private static function add(array &$index, string $dir, string $source): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ([...(glob($dir . '/*.php') ?: []), ...(glob($dir . '/*/*.php') ?: [])] as $file) {
            $index[basename($file, '.php')] ??= $source;
        }
    }
}
