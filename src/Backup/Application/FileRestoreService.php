<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\ArchivePath;
use GP247\Core\Backup\Domain\FileRestorePolicy;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;

/**
 * Writes the files of one file group of an archive back to the site (R3, command line only —
 * US-backup-restore-cli-restore). Nothing from the archive is executed, and:
 *
 *  - every path is checked (ArchivePath) and must stay inside the group's folder; a path whose
 *    existing folders include a link (symlink / junction) is skipped;
 *  - files that already exist are overwritten, the previous version kept in the vault
 *    (restore-files/<job>/<group>/…) for 24 hours; files the archive does not have are left alone;
 *  - the upload folder never receives a file a web server could run or that changes its set-up
 *    (FileRestorePolicy) — skipped and listed;
 *  - a file is written to a temporary name and renamed when complete.
 *
 * Which groups may be written at all (code, .env) is FileRestorePolicy's decision, made before.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-cli-restore, US-backup-restore-post-incident-mode
 * @aidlc-adr backup-engine_tamper-evidence
 */
final class FileRestoreService
{
    /** @var list<string> Groups that are one file (the archive path is that file's name). */
    private const SINGLE_FILE = [FileRestorePolicy::ENV, FileRestorePolicy::LOCK];

    private ScopeResolver $resolver;
    private LocalVault $vault;

    /**
     * @param ScopeResolver|null $resolver
     * @param LocalVault|null    $vault
     */
    public function __construct(?ScopeResolver $resolver = null, ?LocalVault $vault = null)
    {
        $this->resolver = $resolver ?? new ScopeResolver;
        $this->vault = $vault ?? app(LocalVault::class);
    }

    /**
     * @param ArchiveReader $reader
     * @return list<string> File groups the archive holds.
     *
     * @aidlc-story US-backup-restore-cli-restore
     */
    public static function groupsIn(ArchiveReader $reader): array
    {
        return array_map('strval', array_keys((array) ($reader->manifest()['files'] ?? [])));
    }

    /**
     * Key-by-key difference between the archive's .env and the site's — names only, every value
     * hidden (status added / removed / changed).
     *
     * @param ArchiveReader $reader
     * @return array{keys: list<array{key: string, status: string}>, archive_has_env: bool}
     * @throws RestoreRefused file_group_unknown When the archive has no .env.
     *
     * @aidlc-story US-backup-restore-cli-restore
     */
    public function envDiff(ArchiveReader $reader): array
    {
        $archive = self::parseEnv($this->groupContent($reader, FileRestorePolicy::ENV));
        $path = $this->target(FileRestorePolicy::ENV);
        $site = self::parseEnv(is_file($path) ? (string) file_get_contents($path) : '');
        $keys = [];
        foreach (array_unique([...array_keys($archive), ...array_keys($site)]) as $key) {
            $status = match (true) {
                !array_key_exists($key, $site) => 'added',
                !array_key_exists($key, $archive) => 'removed',
                !hash_equals($site[$key], $archive[$key]) => 'changed',
                default => null,
            };
            if ($status !== null) {
                $keys[] = ['key' => (string) $key, 'status' => $status];
            }
        }
        usort($keys, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return ['keys' => $keys, 'archive_has_env' => true];
    }

    /**
     * @param ArchiveReader $reader
     * @param string        $group
     * @param int           $jobId  Restore run the kept copies belong to.
     * @param bool          $strict Archive of unverified origin: stricter upload file types.
     * @return array{group: string, written: int, skipped: array<string, string>, kept: int, copies: string|null}
     *         skipped: path => reason (blocked | unsafe_path | link | outside | duplicate).
     * @throws RestoreRefused file_group_unknown
     *
     * @aidlc-story US-backup-restore-cli-restore
     */
    public function restore(ArchiveReader $reader, string $group, int $jobId, bool $strict = false): array
    {
        if (!in_array($group, FileRestorePolicy::RESTORABLE, true) || !in_array($group, self::groupsIn($reader), true)) {
            throw new RestoreRefused(RestoreRefused::FILE_GROUP_UNKNOWN, $group);
        }
        $root = $this->root($group);
        $blocked = FileRestorePolicy::blockedExtensions($strict);
        $result = ['group' => $group, 'written' => 0, 'skipped' => [], 'kept' => 0, 'copies' => null];
        $open = null;
        $done = [];
        try {
            foreach ($reader->fileChunks($group) as $chunk) {
                $path = (string) $chunk['path'];
                if ($open !== null && $open['path'] !== $path) {
                    $this->abandon($open);
                    $open = null;
                }
                if ($open === null) {
                    if (isset($result['skipped'][$path])) {
                        continue;
                    }
                    if (isset($done[$path])) {
                        // The same path twice would overwrite the restored copy (and the kept original).
                        $result['skipped'][$path] = 'duplicate';
                        continue;
                    }
                    $reason = $this->refusal($group, $root, $path, $blocked);
                    if ($reason !== null) {
                        $result['skipped'][$path] = $reason;
                        continue;
                    }
                    $open = $this->begin($root, $path);
                }
                if ((int) $chunk['offset'] !== $open['written']) {
                    // Chunks of one file come in order; anything else is not written.
                    $this->abandon($open);
                    $open = null;
                    $result['skipped'][$path] = 'unsafe_path';
                    continue;
                }
                fwrite($open['handle'], (string) $chunk['data']);
                $open['written'] += strlen((string) $chunk['data']);
                if ($open['written'] >= (int) $chunk['size']) {
                    $kept = $this->finish($open, $group, $jobId, (int) $chunk['mtime']);
                    $done[$path] = true;
                    $result['written']++;
                    $result['kept'] += $kept ? 1 : 0;
                    $open = null;
                }
            }
        } finally {
            if ($open !== null) {
                $this->abandon($open);
            }
        }
        if ($result['kept'] > 0) {
            $result['copies'] = $this->vault->restoreFilesDir($jobId) . DIRECTORY_SEPARATOR . $group;
        }

        return $result;
    }

    /**
     * Remove copies kept by restores older than gp247-config.backup.restore_files_keep_hours.
     *
     * @return int Folders removed.
     *
     * @aidlc-story US-backup-restore-cli-restore
     */
    public function sweep(): int
    {
        $parent = $this->vault->root() . DIRECTORY_SEPARATOR . 'restore-files';
        $hours = max(1, (int) config('gp247-config.backup.restore_files_keep_hours', 24));
        $removed = 0;
        foreach (@scandir($parent) ?: [] as $entry) {
            $dir = $parent . DIRECTORY_SEPARATOR . $entry;
            if (ctype_digit($entry) && is_dir($dir) && !is_link($dir) && time() - (int) filemtime($dir) > $hours * 3600) {
                self::removeTree($dir);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @param string       $group
     * @param string       $root
     * @param string       $path
     * @param list<string> $blocked
     * @return string|null Why the file is not written, null when it may be.
     */
    private function refusal(string $group, string $root, string $path, array $blocked): ?string
    {
        if (!ArchivePath::isSafe($path)) {
            return 'unsafe_path';
        }
        if (in_array($group, self::SINGLE_FILE, true)) {
            return $path === basename($this->target($group)) ? null : 'outside';
        }
        if ($group === FileRestorePolicy::UPLOADS && FileRestorePolicy::isBlockedUpload($path, $blocked)) {
            return 'blocked';
        }
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current) || (file_exists($current) && self::isJunction($current))) {
                return 'link';
            }
        }

        return null;
    }

    /**
     * @param string $root
     * @param string $path
     * @return array{path: string, target: string, temp: string, handle: resource, written: int}
     */
    private function begin(string $root, string $path): array
    {
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $dir = dirname($target);
        $lexicalRoot = self::normalise($root);
        if (!str_starts_with(self::normalise($target), $lexicalRoot . '/')) {
            throw new RestoreRefused(RestoreRefused::FILES_NOT_ALLOWED, 'outside');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create a folder for a restored file.');
        }
        $realRoot = self::normalise(realpath($root) ?: $root);
        $realDir = self::normalise(realpath($dir) ?: $dir);
        if ($realDir !== $realRoot && !str_starts_with($realDir . '/', $realRoot . '/')) {
            throw new RestoreRefused(RestoreRefused::FILES_NOT_ALLOWED, 'outside');
        }
        $temp = $target . '.gp247tmp-' . bin2hex(random_bytes(4));
        $handle = fopen($temp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot write a restored file.');
        }

        return ['path' => $path, 'target' => $target, 'temp' => $temp, 'handle' => $handle, 'written' => 0];
    }

    /**
     * @param array{path: string, target: string, temp: string, handle: resource, written: int} $open
     * @param string $group
     * @param int    $jobId
     * @param int    $mtime
     * @return bool A previous version was kept.
     */
    private function finish(array $open, string $group, int $jobId, int $mtime): bool
    {
        fclose($open['handle']);
        $kept = false;
        // WHY: keep the mode of the file being replaced (a .env at 0600 must not become readable by others).
        $mode = is_file($open['target']) ? (fileperms($open['target']) & 0777) : ($group === FileRestorePolicy::ENV ? 0600 : null);
        if ($mode !== null) {
            @chmod($open['temp'], $mode);
        }
        if (is_file($open['target'])) {
            $copy = $this->vault->restoreFilesDir($jobId) . DIRECTORY_SEPARATOR . $group . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $open['path']);
            if (!is_dir(dirname($copy))) {
                @mkdir(dirname($copy), 0700, true);
            }
            // An existing kept copy is the original: never replaced.
            if (!is_file($copy) && !@copy($open['target'], $copy)) {
                @unlink($open['temp']);
                throw new \RuntimeException('Cannot keep the previous version of a file.');
            }
            $kept = true;
        }
        if (!@rename($open['temp'], $open['target'])) {
            // Windows refuses to rename over an existing file in some set-ups.
            if (!(@unlink($open['target']) && @rename($open['temp'], $open['target']))) {
                @unlink($open['temp']);
                throw new \RuntimeException('Cannot put a restored file in place.');
            }
        }
        if ($mtime > 0) {
            @touch($open['target'], $mtime);
        }

        return $kept;
    }

    /**
     * @param array{temp: string, handle: resource} $open
     * @return void
     */
    private function abandon(array $open): void
    {
        if (is_resource($open['handle'])) {
            fclose($open['handle']);
        }
        @unlink($open['temp']);
    }

    /**
     * @param string $group
     * @return string Folder the group's files go into.
     * @throws RestoreRefused file_group_unknown
     */
    private function root(string $group): string
    {
        if (in_array($group, self::SINGLE_FILE, true)) {
            return dirname($this->target($group));
        }
        $groups = $this->resolver->fileGroups();
        if (!isset($groups[$group])) {
            throw new RestoreRefused(RestoreRefused::FILE_GROUP_UNKNOWN, $group);
        }
        $root = rtrim($groups[$group]->path, '/\\');
        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new \RuntimeException('Cannot create the folder of a file group.');
        }

        return $root;
    }

    /**
     * @param string $group A single-file group.
     * @return string Configured file path.
     * @throws RestoreRefused file_group_unknown
     */
    private function target(string $group): string
    {
        $path = config('gp247-config.backup.file_groups.' . $group . '.path');
        if (!is_string($path) || $path === '') {
            throw new RestoreRefused(RestoreRefused::FILE_GROUP_UNKNOWN, $group);
        }

        return $path;
    }

    /**
     * @param ArchiveReader $reader
     * @param string        $group
     * @return string Whole content of a single-file group.
     * @throws RestoreRefused
     */
    private function groupContent(ArchiveReader $reader, string $group): string
    {
        if (!in_array($group, self::groupsIn($reader), true)) {
            throw new RestoreRefused(RestoreRefused::FILE_GROUP_UNKNOWN, $group);
        }
        $content = '';
        foreach ($reader->fileChunks($group) as $chunk) {
            $content .= (string) $chunk['data'];
        }

        return $content;
    }

    /**
     * @param string $content
     * @return array<string, string> KEY => raw value (comments and blank lines ignored).
     */
    private static function parseEnv(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=(.*)$/', $line, $m) === 1) {
                $out[$m[1]] = trim($m[2]);
            }
        }

        return $out;
    }

    /**
     * @param string $path
     * @return bool A Windows junction (is_link() does not report it).
     */
    private static function isJunction(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || !is_dir($path)) {
            return false;
        }
        $real = realpath($path);

        return $real !== false && self::normalise($real) !== self::normalise(realpath(dirname($path)) . '/' . basename($path));
    }

    /**
     * @param string $path
     * @return string
     */
    private static function normalise(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /**
     * @param string $dir Folder inside the vault's restore-files area.
     * @return void
     */
    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
