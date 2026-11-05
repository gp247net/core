<?php

namespace GP247\Core\Backup\Application;

/**
 * Chooses which successful archives may be removed. Free uses {@see CountRetentionPolicy};
 * BackupPro can register another strategy (daily / weekly / monthly) through
 * `gp247-config.backup.retention_policy` without touching the core.
 *
 * Contract every policy must keep: never select a pinned archive, the newest successful
 * archive, or an archive whose off-site copy is pending or failed.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-retention
 * @aidlc-adr backup-engine_core-module-layout
 */
interface RetentionPolicy
{
    /**
     * @param list<object>                                   $archives Successful archives, newest first
     *                                                                 (id, reason, kind, pinned, offsite, created_at).
     * @param array{keep: int, keep_pre_change: int}         $settings Site settings.
     * @return list<string> Archive ids to remove.
     */
    public function select(array $archives, array $settings): array;
}
