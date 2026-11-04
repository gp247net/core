<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\Schema;

/**
 * The on/off switch of the backup feature. "On" needs both the switch and the backup tables
 * really present (core files updated but gp247:update not run yet ⇒ off), so callers never act
 * on a half-installed area. Turning it off never deletes archives or keys.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-install-lifecycle
 * @aidlc-adr backup-engine_core-module-layout
 */
final class BackupFeature
{
    public const CONFIG_KEY = 'backup_enabled';

    /**
     * @return bool
     */
    public static function enabled(): bool
    {
        try {
            if (!Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'backup_archive')) {
                return false;
            }

            return (string) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::CONFIG_KEY)->value('value') === '1';
        } catch (\Throwable $e) {
            // Database unreachable during boot / install: treat as off, never break the caller.
            return false;
        }
    }

    /**
     * @param bool $on
     * @return void
     */
    public static function setEnabled(bool $on): void
    {
        AdminConfig::setConfigValue('backup', self::CONFIG_KEY, GP247_STORE_ID_GLOBAL, $on ? '1' : '0', 0);
    }
}
