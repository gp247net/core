<?php

namespace GP247\Core\AdminShell\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * The "System alerts" block row of the admin dashboard (`admin_home`).
 *
 * WHY a helper: `php artisan gp247:update` re-runs the core seeder after the upgrade
 * migrations on every update, and `admin_home` has no unique key on `view`, so an
 * `insertOrIgnore` without an id adds the block again each time (core 3.10.0 showed
 * two blocks after the first update). The block is found by its view instead — one
 * rule for the install seeder and the upgrade migrations.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
final class AdminAlertBlock
{
    public const VIEW = 'gp247-admin::component.alerts';

    private const SORT = 1000;

    /**
     * Add the block when no row has its view yet. Idempotent; a row the owner moved or
     * disabled is left as it is.
     *
     * @param ConnectionInterface $db Connection holding the admin tables.
     * @return void
     */
    public static function ensure(ConnectionInterface $db): void
    {
        $table = $db->table(GP247_DB_PREFIX . 'admin_home');
        if ($table->clone()->where('view', self::VIEW)->exists()) {
            return;
        }

        // WHY max+1: stay above blocks a site already sorted higher than the default 1000.
        $sort = max(self::SORT, (int) $table->clone()->max('sort') + 1);
        $table->clone()->insert([
            'view' => self::VIEW,
            'size' => 12,
            'sort' => $sort,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Keep the oldest block row (lowest id) and delete the copies. Idempotent; other
     * dashboard blocks are never touched.
     *
     * @param ConnectionInterface $db Connection holding the admin tables.
     * @return int Number of duplicate rows removed.
     */
    public static function dedupe(ConnectionInterface $db): int
    {
        $table = $db->table(GP247_DB_PREFIX . 'admin_home');
        $keep = $table->clone()->where('view', self::VIEW)->min('id');
        if ($keep === null) {
            return 0;
        }

        return $table->clone()->where('view', self::VIEW)->where('id', '<>', $keep)->delete();
    }
}
