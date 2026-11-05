<?php

namespace GP247\Core\Backup\Domain;

/**
 * A group of files that is not rebuilt from package sources: uploads, app/GP247, .env,
 * composer.lock. Either a directory (walked recursively) or a single file.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-full-backup, US-backup-engine-selective-backup
 * @aidlc-adr backup-engine_archive-format
 */
final class FileGroup
{
    /**
     * @param string       $key     Group key ("uploads", "extensions", "env", "lock").
     * @param string       $path    Absolute directory or file path.
     * @param list<string> $exclude Directory names skipped at any depth (".git", "node_modules").
     */
    public function __construct(
        public readonly string $key,
        public readonly string $path,
        public readonly array $exclude = [],
    ) {
    }

    /**
     * @return bool The group is one file rather than a directory.
     */
    public function isFile(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return bool Nothing to back up (missing path) — the group is skipped, not an error.
     */
    public function missing(): bool
    {
        return !is_file($this->path) && !is_dir($this->path);
    }
}
