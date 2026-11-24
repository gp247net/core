<?php

namespace GP247\Core\PersonalData\Infrastructure\Requests;

use GP247\Core\PersonalData\Application\Requests\PrivacySettings;
use Illuminate\Database\ConnectionInterface;

/**
 * Permission, menu item and default settings of the "Personal data requests" screen, inserted
 * when missing. Called by the install seeder, which `gp247:update` runs again — existing sites
 * get them without a step of their own. The permission is given to no role: only
 * Administrators can use the screen until the owner assigns it to a role.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-install-lifecycle, US-personal-data-requests-permission
 * @aidlc-adr personal-data-requests_access-and-settings
 */
final class PersonalDataInstaller
{
    public const PERMISSION_SLUG = 'personal_data.requests';
    public const PERMISSION_NAME = 'Personal data requests';
    public const MENU_URI = 'admin::personal_data_requests';
    public const SCREEN_PATH = 'personal_data_requests';
    public const SETTINGS_PATH = 'personal_data_settings';
    private const PARENT_KEY = 'ADMIN_SECURITY';

    /**
     * @param ConnectionInterface $db
     * @param string|null         $adminPrefix Admin URL prefix (GP247_ADMIN_PREFIX by default).
     * @return void
     */
    public static function ensure(ConnectionInterface $db, ?string $adminPrefix = null): void
    {
        $schema = $db->getSchemaBuilder();
        $p = GP247_DB_PREFIX;
        if ($schema->hasTable($p . 'admin_config')) {
            // WHY insertOrIgnore: unique (key, store_id) keeps what the owner already chose.
            $db->table($p . 'admin_config')->insertOrIgnore(PrivacySettings::defaultRows());
        }
        if ($schema->hasTable($p . 'admin_permission') && !$db->table($p . 'admin_permission')->where('slug', self::PERMISSION_SLUG)->exists()) {
            $db->table($p . 'admin_permission')->insertOrIgnore([
                'name' => self::PERMISSION_NAME,
                'slug' => self::PERMISSION_SLUG,
                'http_uri' => self::permissionUri($adminPrefix ?? GP247_ADMIN_PREFIX),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        if ($schema->hasTable($p . 'admin_menu') && !$db->table($p . 'admin_menu')->where('uri', self::MENU_URI)->exists()) {
            $db->table($p . 'admin_menu')->insert([
                'parent_id' => (int) $db->table($p . 'admin_menu')->where('key', self::PARENT_KEY)->value('id'),
                'sort' => 3,
                'title' => 'admin.menu_titles.personal_data_requests',
                'icon' => 'fas fa-user-shield',
                'uri' => self::MENU_URI,
                'key' => null,
                'type' => 0,
            ]);
        }
    }

    /**
     * @param string $prefix Admin URL prefix.
     * @return string RBAC http_uri of the screen (list, actions of the Livewire screen).
     */
    public static function permissionUri(string $prefix): string
    {
        return 'ANY::' . $prefix . '/' . self::SCREEN_PATH;
    }
}
