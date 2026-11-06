<?php

namespace GP247\Core\Backup\Console\Concerns;

/**
 * Where the backup commands read `--secret-stdin` from: standard input, or a stream bound in the
 * container under BINDING (tests drive the commands without a real terminal).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-cli
 */
final class BackupStdin
{
    public const BINDING = 'gp247.backup.stdin';

    /**
     * @return resource|null
     */
    public static function stream()
    {
        $stream = app()->bound(self::BINDING) ? app(self::BINDING) : (defined('STDIN') ? STDIN : null);

        return is_resource($stream) ? $stream : null;
    }
}
