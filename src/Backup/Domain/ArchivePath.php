<?php

namespace GP247\Core\Backup\Domain;

/**
 * A relative file path read from an archive's file groups. The path is data coming from the
 * archive, so before anything is written to disk it must stay inside the group root: no
 * absolute path, drive letter, UNC share, backslash, "..", empty or "." segment, NUL byte.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-full-database
 * @aidlc-adr backup-engine_archive-format
 */
final class ArchivePath
{
    public const MAX_BYTES = 4096;

    /**
     * @param string $path Path as written in the archive ("product/shirt.jpg").
     * @return string The same path.
     * @throws ArchiveRejected (path_invalid) When it could leave the group root.
     */
    public static function assertSafe(string $path): string
    {
        if (!self::isSafe($path)) {
            throw new ArchiveRejected(ArchiveRejected::PATH_INVALID);
        }

        return $path;
    }

    /**
     * @param string $path
     * @return bool
     */
    public static function isSafe(string $path): bool
    {
        if ($path === '' || strlen($path) > self::MAX_BYTES || !mb_check_encoding($path, 'UTF-8')) {
            return false;
        }
        // Backslash covers Windows separators and UNC (\\server); ":" covers drive letters and
        // alternate data streams; control characters include NUL.
        if (preg_match('/[\\\\:\x00-\x1f]/', $path) === 1 || $path[0] === '/') {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            // WHY trailing dot / space too: Windows strips them, so "..." or "a." name another entry.
            if ($segment === '' || $segment === '.' || $segment === '..' || $segment !== rtrim($segment, '. ')) {
                return false;
            }
        }

        return true;
    }
}
