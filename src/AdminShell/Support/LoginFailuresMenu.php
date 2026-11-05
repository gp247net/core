<?php

namespace GP247\Core\AdminShell\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Menu item of the "Login failures" screen, in the "Security" group next to the
 * operation log. Found and inserted by its uri (no fixed id), like SystemAlertsMenu —
 * one helper for the install seeder and the upgrade migration.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-list
 * @aidlc-adr admin-shell_login-failure-log
 */
final class LoginFailuresMenu
{
    public const URI = 'admin::login_failures';

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
            'sort' => 1,
            'title' => 'admin.menu_titles.login_failures',
            'icon' => 'fas fa-user-lock',
            'uri' => self::URI,
            'key' => null,
            'type' => 0,
        ]);
    }

    /**
     * Remove the menu item (migration rollback).
     *
     * @param ConnectionInterface $db
     * @return void
     */
    public static function remove(ConnectionInterface $db): void
    {
        $db->table(GP247_DB_PREFIX . 'admin_menu')->where('uri', self::URI)->delete();
    }
}
