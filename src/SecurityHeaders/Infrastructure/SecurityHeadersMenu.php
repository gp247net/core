<?php

namespace GP247\Core\SecurityHeaders\Infrastructure;

use Illuminate\Database\ConnectionInterface;

/**
 * Menu item of the "Security headers" screen, in the "Security" group. Found and inserted
 * by its uri (no fixed id), like LoginFailuresMenu — the core seeder calls it on every
 * install and every `gp247:update`.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-install-lifecycle, US-security-headers-settings
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
final class SecurityHeadersMenu
{
    public const URI = 'admin::security_headers';

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
            'sort' => 2,
            'title' => 'admin.menu_titles.security_headers',
            'icon' => 'fas fa-shield-alt',
            'uri' => self::URI,
            'key' => null,
            'type' => 0,
        ]);
    }
}
