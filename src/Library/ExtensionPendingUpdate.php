<?php

namespace GP247\Core\Library;

use Illuminate\Support\Facades\File;

/**
 * A library update whose files were replaced but whose data hook has not run yet.
 *
 * PHP cannot load a class twice. When the request that replaces an extension's files has
 * already loaded a class of that extension from its folder (for instance another plugin
 * read a constant of its AppConfig at boot), calling AppConfig::update() in that request
 * would run the OLD code. The update manager then leaves the hook to a fresh request and
 * writes this marker next to the backups, so that request (ExtensionDataUpdater) knows
 * which backup to restore when the hook fails. The marker is a file — no schema change.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-005, US-PLG-local-update-data-converge
 * @aidlc-adr plugin-manager_extension-update-fresh-process
 */
class ExtensionPendingUpdate
{
    public const FILE = 'pending-update.json';

    /**
     * Path of the marker of one extension.
     *
     * @param string $type Plugins|Templates.
     * @param string $key  Extension key.
     * @return string
     */
    public static function path(string $type, string $key): string
    {
        return storage_path('backups/extensions/'.self::type($type).'/'.$key.'/'.self::FILE);
    }

    /**
     * Read the marker of one extension.
     *
     * @param string $type Plugins|Templates.
     * @param string $key  Extension key.
     * @return array{from: ?string, to: string, backup: string, at: string}|null Null when absent or unreadable.
     */
    public static function read(string $type, string $key): ?array
    {
        $file = self::path($type, $key);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || empty($data['to']) || empty($data['backup'])) {
            return null;
        }

        return [
            'from' => isset($data['from']) && $data['from'] !== '' ? (string) $data['from'] : null,
            'to' => (string) $data['to'],
            'backup' => (string) $data['backup'],
            'at' => (string) ($data['at'] ?? ''),
        ];
    }

    /**
     * Write the marker of one extension.
     *
     * @param string      $type   Plugins|Templates.
     * @param string      $key    Extension key.
     * @param string|null $from   Version the files had before the update (passed to the hook).
     * @param string      $to     Version of the files now on disk.
     * @param string      $backup Backup folder of this update.
     * @return void
     * @throws \RuntimeException When the marker cannot be written.
     */
    public static function write(string $type, string $key, ?string $from, string $to, string $backup): void
    {
        $file = self::path($type, $key);
        File::ensureDirectoryExists(dirname($file));
        $json = json_encode(['from' => $from, 'to' => $to, 'backup' => $backup, 'at' => now()->toIso8601String()], JSON_UNESCAPED_SLASHES);
        if (file_put_contents($file, $json) === false) {
            throw new \RuntimeException('Cannot write '.$file);
        }
    }

    /**
     * Remove the marker of one extension (no-op when absent).
     *
     * @param string $type Plugins|Templates.
     * @param string $key  Extension key.
     * @return void
     */
    public static function forget(string $type, string $key): void
    {
        $file = self::path($type, $key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Has this process already loaded a class of the extension FROM its folder?
     *
     * WHY the folder check: only code read from the files that were just replaced is stale;
     * a class of that namespace declared elsewhere is not the extension's old code.
     *
     * @param string $type Plugins|Templates.
     * @param string $key  Extension key.
     * @return bool
     */
    public static function codeLoaded(string $type, string $key): bool
    {
        // WHY ltrim: the helper returns a fully-qualified name ("\App\…"); declared names have no leading "\".
        $prefix = ltrim((string) gp247_extension_get_namespace(type: self::type($type), key: $key), '\\').'\\';
        $dir = app_path('GP247/'.self::type($type).'/'.$key);
        $folder = strtolower(self::normalize(realpath($dir) ?: $dir)).'/';
        foreach (get_declared_classes() as $class) {
            if (strncasecmp($class, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            $file = (new \ReflectionClass($class))->getFileName();
            if (!is_string($file)) {
                continue;
            }
            // WHY lower-case: Windows paths are case-insensitive and may differ in drive-letter case.
            if (str_starts_with(strtolower(self::normalize(realpath($file) ?: $file)), $folder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the OPcache entry of every PHP file of a folder, so the next request compiles the
     * files just copied even when OPcache does not check timestamps.
     *
     * @param string $folder Absolute folder path.
     * @return void
     */
    public static function invalidateOpcache(string $folder): void
    {
        if (!function_exists('opcache_invalidate') || !is_dir($folder)) {
            return;
        }
        foreach (File::allFiles($folder) as $file) {
            if (strtolower($file->getExtension()) === 'php') {
                @opcache_invalidate($file->getPathname(), true);
            }
        }
    }

    /**
     * @param string $type
     * @return string Plugins|Templates.
     */
    private static function type(string $type): string
    {
        return $type === 'Templates' ? 'Templates' : 'Plugins';
    }

    /**
     * @param string $path
     * @return string Path with forward slashes and no trailing slash.
     */
    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
