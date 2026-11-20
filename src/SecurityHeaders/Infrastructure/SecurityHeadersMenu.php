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

    /** Icon of the menu item — distinct from the shield of the Security group and System alerts. */
    public const ICON = 'fas fa-globe';

    /** Icon that core 3.16.0 wrote; replaced on update unless the owner picked another one. */
    private const OLD_ICON = 'fas fa-shield-alt';

    /**
     * Insert the menu item when no row has its uri yet, and replace the icon core 3.16.0
     * wrote. Idempotent.
     *
     * @param ConnectionInterface $db Connection holding the admin tables.
     * @return void
     */
    public static function ensure(ConnectionInterface $db): void
    {
        $table = GP247_DB_PREFIX . 'admin_menu';
        if ($db->table($table)->where('uri', self::URI)->exists()) {
            // WHY: only the old default is replaced, so an icon the owner chose on the Menu screen is kept.
            $db->table($table)->where('uri', self::URI)->where('icon', self::OLD_ICON)->update(['icon' => self::ICON]);

            return;
        }
        $parent = (int) $db->table($table)->where('key', self::PARENT_KEY)->value('id');
        $db->table($table)->insert([
            'parent_id' => $parent,
            'sort' => 2,
            'title' => 'admin.menu_titles.security_headers',
            'icon' => self::ICON,
            'uri' => self::URI,
            'key' => null,
            'type' => 0,
        ]);
    }
}
