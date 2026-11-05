<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The local place archives live in: outside the public web root, behind a directory name
 * nobody can guess, with deny files for the web servers that read them (Apache .htaccess,
 * IIS web.config) and an empty index.html. Nginx does not read such files — the vault stays
 * outside `public/`, and the doctor check (BK-7) tries to download a canary over APP_URL.
 *
 * Default: storage/app/private/gp247-backup/<24 random chars>/ (the random part is created on
 * first use and kept in admin_config). A custom absolute path may be set, never inside the
 * public web root.
 *
 * Not final: a host integration (or a test) may override freeBytes() where disk_free_space()
 * reports a quota-less figure.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-local
 * @aidlc-adr backup-storage_optional-adapters
 */
class LocalVault
{
    public const CONFIG_DIR = 'backup_local_dir';
    public const CONFIG_PATH = 'backup_local_path';
    private const CONFIG_GROUP = 'backup';

    private const HTACCESS = "# GP247 backup vault: never served over the web.\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
        . "    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n"
        . "        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n"
        . "  </system.webServer>\n</configuration>\n";

    private ?string $resolved = null;

    /**
     * @param string|null $root Explicit vault root (tests); null = from admin_config.
     */
    public function __construct(private readonly ?string $root = null)
    {
    }

    /**
     * @return string Absolute vault directory, created with its deny files.
     * @throws BackupRefused When the configured path is inside the public web root.
     */
    public function root(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $root = $this->root ?? $this->configuredRoot();
        self::assertOutsideWebRoot($root);
        self::ensureDirectory($root);
        if ($this->root === null && self::configValue(self::CONFIG_PATH) === '') {
            // Default layout: also protect the shared parent (storage/app/private/gp247-backup).
            self::ensureDirectory(dirname($root));
        }

        return $this->resolved = $root;
    }

    /**
     * @param string $archiveId ULID.
     * @return string Directory of that archive inside the vault (not created).
     */
    public function archiveDir(string $archiveId): string
    {
        self::assertArchiveId($archiveId);

        return $this->root() . DIRECTORY_SEPARATOR . $archiveId;
    }

    /**
     * Remove one archive directory. Only a ULID-named directory directly inside the vault is
     * ever touched.
     *
     * @param string $archiveId
     * @return void
     */
    public function deleteArchive(string $archiveId): void
    {
        $dir = $this->archiveDir($archiveId);
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            // Archives are flat (header, manifest, parts): anything else is left alone.
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param string $archiveId
     * @return int Bytes used by an archive on disk.
     */
    public function archiveBytes(string $archiveId): int
    {
        $dir = $this->archiveDir($archiveId);
        $bytes = 0;
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
            if (is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                $bytes += (int) filesize($dir . DIRECTORY_SEPARATOR . $entry);
            }
        }

        return $bytes;
    }

    /**
     * @return int|null Free bytes on the vault's disk; null when the host disables the check.
     */
    public function freeBytes(): ?int
    {
        if (!function_exists('disk_free_space')) {
            return null;
        }
        $free = @disk_free_space($this->root());

        return $free === false ? null : (int) $free;
    }

    /**
     * Store a custom vault path (null / '' = back to the default).
     *
     * @param string|null $path Absolute path outside the public web root.
     * @return void
     * @throws BackupRefused When the path is relative or inside the public web root.
     */
    public static function setCustomPath(?string $path): void
    {
        $path = trim((string) $path);
        if ($path !== '') {
            if (!self::isAbsolute($path) || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path) === 1) {
                throw new BackupRefused(BackupRefused::VAULT_UNSAFE, 'path must be absolute');
            }
            self::assertOutsideWebRoot($path);
            // A vault inside a backed-up folder would copy every archive into the next one.
            $target = self::normalise(self::resolveExisting($path));
            foreach ((new \GP247\Core\Backup\Application\ScopeResolver)->fileGroups() as $group) {
                $root = self::normalise(self::resolveExisting($group->path));
                if (!$group->isFile() && ($target === $root || str_starts_with($target, $root . '/'))) {
                    throw new BackupRefused(BackupRefused::VAULT_UNSAFE, 'inside a backed-up folder');
                }
            }
        }
        AdminConfig::setConfigValue(self::CONFIG_GROUP, self::CONFIG_PATH, GP247_STORE_ID_GLOBAL, $path, 0);
    }

    /**
     * @param string $path
     * @return void
     * @throws BackupRefused When $path is the public web root, inside it, or inside a folder the
     *                       web root reaches through a link (public/storage → storage/app/public).
     */
    public static function assertOutsideWebRoot(string $path): void
    {
        $target = self::normalise(self::resolveExisting($path));
        foreach (self::webReachable() as $root) {
            if ($target === $root || str_starts_with($target, $root . '/')) {
                throw new BackupRefused(BackupRefused::VAULT_UNSAFE, 'reachable from the public web root');
            }
        }
    }

    /**
     * @return list<string> Normalised folders the web server may serve.
     */
    private static function webReachable(): array
    {
        $public = realpath(public_path()) ?: public_path();
        $roots = [self::normalise($public), self::normalise(self::resolveExisting(storage_path('app' . DIRECTORY_SEPARATOR . 'public')))];
        foreach (@scandir($public) ?: [] as $entry) {
            $path = $public . DIRECTORY_SEPARATOR . $entry;
            if ($entry !== '.' && $entry !== '..' && is_dir($path) && ($real = realpath($path)) !== false
                && self::normalise($real) !== self::normalise($public . '/' . $entry)) {
                // A link or junction directly under the web root.
                $roots[] = self::normalise($real);
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return string
     */
    private function configuredRoot(): string
    {
        $custom = self::configValue(self::CONFIG_PATH);
        if ($custom !== '') {
            return rtrim($custom, '/\\');
        }
        $dir = self::configValue(self::CONFIG_DIR);
        if (preg_match('/^[a-z0-9]{24}$/D', $dir) !== 1) {
            AdminConfig::setConfigValue(self::CONFIG_GROUP, self::CONFIG_DIR, GP247_STORE_ID_GLOBAL, Str::lower(Str::random(24)), 0);
            // Re-read: when two first uses race, both continue with the value that was kept.
            $dir = self::configValue(self::CONFIG_DIR);
            if (preg_match('/^[a-z0-9]{24}$/D', $dir) !== 1) {
                throw new RuntimeException('Cannot record the backup directory.');
            }
        }

        return storage_path('app' . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'gp247-backup' . DIRECTORY_SEPARATOR . $dir);
    }

    /**
     * @param string $key
     * @return string
     */
    private static function configValue(string $key): string
    {
        return trim((string) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', $key)->value('value'));
    }

    /**
     * @param string $dir
     * @return void
     */
    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the backup directory.');
        }
        $files = ['.htaccess' => self::HTACCESS, 'web.config' => self::WEB_CONFIG, 'index.html' => ''];
        foreach ($files as $name => $content) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) && file_put_contents($path, $content, LOCK_EX) === false) {
                throw new RuntimeException('Cannot protect the backup directory.');
            }
        }
    }

    /**
     * @param string $archiveId
     * @return void
     */
    private static function assertArchiveId(string $archiveId): void
    {
        if (preg_match('/^[0-9A-Z]{26}$/D', $archiveId) !== 1) {
            throw new \InvalidArgumentException('Archive id must be a ULID.');
        }
    }

    /**
     * Resolve the deepest existing ancestor, then append the rest (the vault may not exist yet).
     *
     * @param string $path
     * @return string
     */
    private static function resolveExisting(string $path): string
    {
        $rest = [];
        $current = rtrim($path, '/\\');
        while ($current !== '' && realpath($current) === false) {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            array_unshift($rest, basename($current));
            $current = $parent;
        }
        $base = realpath($current) ?: $current;

        return $rest === [] ? $base : $base . '/' . implode('/', $rest);
    }

    /**
     * @param string $path
     * @return string Forward slashes, no trailing slash, lower case on Windows.
     */
    private static function normalise(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /**
     * @param string $path
     * @return bool
     */
    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1 || str_starts_with($path, '\\\\');
    }
}
