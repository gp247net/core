<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Store\JobRepository;

/**
 * Tells the write-activity registry when a job of the backup area writes (backup, restore,
 * archive import), so an extension that writes in bulk (a data import) waits for it.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-write-activity-exclusion
 * @aidlc-adr backup-engine_write-activity-registry
 */
final class WriteProbe
{
    public const KEY = 'backup';

    /**
     * Registered in gp247-config.core.write_activity. No lease end: backup jobs end as failed after
     * their own stall rules, which frees the slot.
     *
     * @return array{label: string}|null
     */
    public static function probe(): ?array
    {
        if (!(new JobRepository)->hasActiveWriteJob()) {
            return null;
        }
        $label = function_exists('gp247_language_render') ? (string) gp247_language_render('admin.backup.write_activity') : '';

        return ['label' => $label !== '' && $label !== 'admin.backup.write_activity' ? $label : 'Backup or restore'];
    }
}
