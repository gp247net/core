<?php

namespace GP247\Core\AdminShell\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Menu item of the "System alerts" page, in the "Security" group (ADMIN_SECURITY, under
 * "System config") next to the other security screens.
 *
 * WHY no fixed id: gp247/shop and plugins insert menu rows with auto-increment ids, so a
 * fixed id may already be taken on a running site and insertOrIgnore would drop the item
 * silently. The row is found and inserted by its uri instead — one helper for the install
 * seeder and the upgrade migration.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts-page
 * @aidlc-adr admin-shell_system-alerts-page
 */
final class SystemAlertsMenu
{
    public const URI = 'admin::system_alerts';

    private const PARENT_KEY = 'ADMIN_SECURITY';

    /**
     * Insert the menu item when no row has its uri yet. Idempotent.
     *
     * @param ConnectionInterface $db Connection holding the admin tables.
     * @return void
     */
    public static function ensure(ConnectionInterface $db): void
    {
        $table = GP247_DB_PREFIX . 'admin_menu';
        if ($db->table($table)->where('uri', self::URI)->exists()) {
            return;
        }

        $parent = (int) $db->table($table)->where('key', self::PARENT_KEY)->value('id');
        $db->table($table)->insert([
            'parent_id' => $parent,
            'sort' => 10,
            'title' => 'admin.menu_titles.system_alerts',
            'icon' => 'fas fa-shield-alt',
            'uri' => self::URI,
            'key' => null,
            'type' => 0,
        ]);
    }

    /**
     * Remove the menu item (migration rollback).
     *
     * @param ConnectionInterface $db Connection holding the admin tables.
     * @return void
     */
    public static function remove(ConnectionInterface $db): void
    {
        $db->table(GP247_DB_PREFIX . 'admin_menu')->where('uri', self::URI)->delete();
    }
}
