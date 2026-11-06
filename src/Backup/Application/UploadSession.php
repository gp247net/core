<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;

/**
 * Receives a `.gp247bak` file from the backup screen in small pieces, so the upload limit of a
 * shared host (often 2–8 MB per request) never matters: begin (size announced, checked against
 * the configured ceiling and the free disk space) → pieces appended at the expected offset → done.
 * The file lives in the vault's incoming area (outside the web root, deny files) and is removed
 * when the import fails; an upload left unfinished for a day is removed by sweep().
 *
 * One upload at a time, bound to the admin AND the browser session that began it (begun right
 * after the sign-in was re-confirmed; the pieces need no new confirmation, so a long upload is
 * not cut off). An upload with no piece for IDLE_SECONDS no longer blocks a new one; the same
 * admin beginning again drops their unfinished one.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive
 * @aidlc-adr backup-engine_archive-format
 */
final class UploadSession
{
    public const STALE_SECONDS = 86_400;
    public const IDLE_SECONDS = 900;
    private const DEFAULT_MAX_BYTES = 2048 * 1024 * 1024;
    private const DEFAULT_CHUNK_BYTES = 1_048_576;

    private LocalVault $vault;

    /**
     * @param LocalVault|null $vault
     */
    public function __construct(?LocalVault $vault = null)
    {
        $this->vault = $vault ?? app(LocalVault::class);
    }

    /**
     * @return int Largest file accepted (gp247-config.backup.upload_max_bytes).
     */
    public static function maxBytes(): int
    {
        $value = (int) config('gp247-config.backup.upload_max_bytes', self::DEFAULT_MAX_BYTES);

        return $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
    }

    /**
     * @return int Bytes of one piece (gp247-config.backup.upload_chunk_bytes, 64 KiB – 8 MiB).
     */
    public static function chunkBytes(): int
    {
        return max(65_536, min(8_388_608, (int) config('gp247-config.backup.upload_chunk_bytes', self::DEFAULT_CHUNK_BYTES)));
    }

    /**
     * @param int    $total     Announced size of the file.
     * @param string $adminId   Admin uploading.
     * @param string $sessionId Browser session the pieces must come from.
     * @return string Upload id.
     * @throws RestoreRefused upload_too_large | disk_space | already_running
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function begin(int $total, string $adminId, string $sessionId): string
    {
        if ($total < 1 || $total > self::maxBytes()) {
            throw new RestoreRefused(RestoreRefused::UPLOAD_TOO_LARGE, '', ['limit' => self::maxBytes()]);
        }
        $free = $this->vault->freeBytes();
        // Room for the upload AND its unpacked copy.
        if ($free !== null && $free < 2 * $total + 50 * 1024 * 1024) {
            throw new RestoreRefused(RestoreRefused::DISK_SPACE, '', ['free' => $free, 'needed' => 2 * $total]);
        }
        $this->sweep();
        foreach ($this->open() as $openId => $meta) {
            if (!hash_equals((string) $meta['admin_id'], $adminId)) {
                throw new RestoreRefused(RestoreRefused::ALREADY_RUNNING, 'upload');
            }
            // The same person starts over (closed tab, failed upload): their old one goes.
            $this->discard($openId, $adminId);
        }
        $id = bin2hex(random_bytes(16));
        if (file_put_contents($this->partPath($id), '', LOCK_EX) === false) {
            throw new \RuntimeException('Upload area not writable.');
        }
        $this->writeMeta($id, ['total' => $total, 'received' => 0, 'admin_id' => $adminId, 'session' => hash('sha256', $sessionId),
            'started' => time(), 'touched' => time(), 'done' => false]);

        return $id;
    }

    /**
     * @param string $id
     * @param int    $offset  Where the piece starts (must equal the bytes received so far).
     * @param string $bytes
     * @param string $adminId
     * @param string $sessionId Must be the session that began the upload.
     * @return int Bytes received so far.
     * @throws RestoreRefused upload_unknown | upload_offset (context "received") | upload_too_large
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function append(string $id, int $offset, string $bytes, string $adminId, string $sessionId): int
    {
        $meta = $this->meta($id, $adminId);
        if (!hash_equals((string) $meta['session'], hash('sha256', $sessionId))) {
            throw new RestoreRefused(RestoreRefused::UPLOAD_UNKNOWN, 'session');
        }
        if ($meta['done'] || $offset !== $meta['received']) {
            throw new RestoreRefused(RestoreRefused::UPLOAD_OFFSET, '', ['received' => $meta['received']]);
        }
        $length = strlen($bytes);
        if ($length < 1 || $length > self::chunkBytes() || $meta['received'] + $length > $meta['total']) {
            throw new RestoreRefused(RestoreRefused::UPLOAD_TOO_LARGE, 'piece');
        }
        $out = fopen($this->partPath($id), 'r+b');
        try {
            // WHY truncate: a piece whose answer was lost may have been written before the retry.
            ftruncate($out, $meta['received']);
            fseek($out, $meta['received']);
            if (fwrite($out, $bytes) !== $length) {
                throw new \RuntimeException('Upload write failed.');
            }
        } finally {
            fclose($out);
        }
        $meta['received'] += $length;
        $meta['touched'] = time();
        $this->writeMeta($id, $meta);

        return $meta['received'];
    }

    /**
     * @param string $id
     * @param string $adminId
     * @return string Path of the complete file (owned by the backup feature: the import removes it).
     * @throws RestoreRefused upload_unknown | upload_offset
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function complete(string $id, string $adminId): string
    {
        $meta = $this->meta($id, $adminId);
        if ($meta['received'] !== $meta['total'] || filesize($this->partPath($id)) !== $meta['total']) {
            throw new RestoreRefused(RestoreRefused::UPLOAD_OFFSET, '', ['received' => $meta['received']]);
        }
        $meta['done'] = true;
        $this->writeMeta($id, $meta);

        return $this->partPath($id);
    }

    /**
     * Drop the record of a finished upload whose file the import now owns.
     *
     * @param string $id
     * @return void
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function forget(string $id): void
    {
        if (self::validId($id)) {
            @unlink($this->metaPath($id));
        }
    }

    /**
     * Remove an upload of this admin (file and record).
     *
     * @param string $id
     * @param string $adminId Only the admin who began it may remove it.
     * @return void
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function discard(string $id, string $adminId): void
    {
        try {
            $this->meta($id, $adminId);
        } catch (RestoreRefused $e) {
            return;
        }
        $this->remove($id);
    }

    /**
     * @param string $id
     * @return void
     */
    private function remove(string $id): void
    {
        if (self::validId($id)) {
            @unlink($this->partPath($id));
            @unlink($this->metaPath($id));
        }
    }

    /**
     * Remove uploads older than a day, finished or not, and leftover pieces without their record.
     *
     * @return void
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function sweep(): void
    {
        $dir = $this->vault->incomingDir();
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            // Unpack folders of imports that died before or without their job (the job removes its own).
            if (preg_match('/^x[0-9a-f]{16}$/D', $name) === 1 && is_dir($path) && !is_link($path) && time() - (int) @filemtime($path) > self::STALE_SECONDS) {
                \GP247\Core\Backup\Infrastructure\Format\ResumableUnpacker::clean($path);
                continue;
            }
            if (preg_match('/^([0-9a-f]{32})\.(json|upload)$/D', $name, $m) !== 1) {
                continue;
            }
            $meta = @json_decode((string) @file_get_contents($this->metaPath($m[1])), true);
            $started = is_array($meta) ? (int) ($meta['started'] ?? 0) : (int) @filemtime($dir . DIRECTORY_SEPARATOR . $name);
            if (time() - $started > self::STALE_SECONDS) {
                $this->remove($m[1]);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>> Uploads still being sent (not finished, a piece within IDLE_SECONDS), by id.
     */
    private function open(): array
    {
        $open = [];
        foreach (scandir($this->vault->incomingDir()) ?: [] as $name) {
            if (preg_match('/^([0-9a-f]{32})\.json$/D', $name, $m) === 1) {
                $meta = @json_decode((string) @file_get_contents($this->metaPath($m[1])), true);
                if (is_array($meta) && empty($meta['done']) && time() - (int) ($meta['touched'] ?? $meta['started'] ?? 0) <= self::IDLE_SECONDS) {
                    $open[$m[1]] = $meta;
                }
            }
        }

        return $open;
    }

    /**
     * @param string $id
     * @param string $adminId
     * @return array{total: int, received: int, admin_id: string, session: string, started: int, touched: int, done: bool}
     * @throws RestoreRefused upload_unknown
     */
    private function meta(string $id, string $adminId): array
    {
        $meta = self::validId($id) ? @json_decode((string) @file_get_contents($this->metaPath($id)), true) : null;
        if (!is_array($meta) || !hash_equals((string) ($meta['admin_id'] ?? ''), $adminId) || !is_file($this->partPath($id))
            || time() - (int) ($meta['started'] ?? 0) > self::STALE_SECONDS) {
            throw new RestoreRefused(RestoreRefused::UPLOAD_UNKNOWN);
        }

        return ['total' => (int) $meta['total'], 'received' => (int) $meta['received'], 'admin_id' => (string) $meta['admin_id'], 'session' => (string) ($meta['session'] ?? ''),
            'started' => (int) $meta['started'], 'touched' => (int) ($meta['touched'] ?? $meta['started']), 'done' => (bool) $meta['done']];
    }

    /**
     * @param string               $id
     * @param array<string, mixed> $meta
     * @return void
     */
    private function writeMeta(string $id, array $meta): void
    {
        if (file_put_contents($this->metaPath($id), (string) json_encode($meta), LOCK_EX) === false) {
            throw new \RuntimeException('Upload area not writable.');
        }
    }

    /**
     * @param string $id
     * @return bool
     */
    private static function validId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{32}$/D', $id) === 1;
    }

    /**
     * @param string $id
     * @return string
     */
    private function partPath(string $id): string
    {
        return $this->vault->incomingDir() . DIRECTORY_SEPARATOR . $id . '.upload';
    }

    /**
     * @param string $id
     * @return string
     */
    private function metaPath(string $id): string
    {
        return $this->vault->incomingDir() . DIRECTORY_SEPARATOR . $id . '.json';
    }
}
