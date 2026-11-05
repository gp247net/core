<?php

namespace GP247\Core\Backup\Infrastructure\Install;

use GP247\Core\Backup\Application\BackupFeature;
use Illuminate\Database\ConnectionInterface;

/**
 * Rows of the backup area outside its own tables: the on/off switch, three separate
 * permissions (create · download · restore) and the menu item under "System". One helper for
 * the install seeder and the upgrade migration; only inserts what is missing, so a value the
 * site chose (switch off, renamed menu) is kept. Rows are found by slug / uri, never by a
 * fixed id (shop and plugins take auto-increment ids on running sites).
 *
 * The menu item is visible since the backup screen shipped (BK-4); sites updated from BK-1 get
 * it shown, and the permission URIs widened, by the upgrade migration (`showScreen()`).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-install-lifecycle, US-backup-admin-permissions
 * @aidlc-adr backup-engine_core-module-layout
 */
final class BackupInstaller
{
    public const MENU_URI = 'admin::backup/archives';
    private const PARENT_KEY = 'ADMIN_SYSTEM';

    /** @var array<string, string> Permission slug => name. */
    public const PERMISSIONS = [
        'backup.create' => 'Backup - create',
        'backup.download' => 'Backup - download',
        'backup.restore' => 'Backup - restore',
    ];

    /**
     * Insert the switch, permissions and menu item that are missing. Idempotent.
     *
     * @param ConnectionInterface $db          Connection holding the admin tables.
     * @param string|null         $adminPrefix Admin URL prefix (GP247_ADMIN_PREFIX by default).
     * @return void
     */
    public static function ensure(ConnectionInterface $db, ?string $adminPrefix = null): void
    {
        $schema = $db->getSchemaBuilder();
        $p = GP247_DB_PREFIX;
        $now = date('Y-m-d H:i:s');

        if ($schema->hasTable($p . 'admin_config')) {
            // WHY insertOrIgnore: unique (key, store_id) keeps the value the site already chose.
            $db->table($p . 'admin_config')->insertOrIgnore([
                'group' => 'backup',
                'code' => 'backup',
                'key' => BackupFeature::CONFIG_KEY,
                'value' => '1',
                'security' => 0,
                'store_id' => GP247_STORE_ID_GLOBAL,
                'sort' => 0,
                'detail' => 'admin.backup.enabled',
            ]);
        }

        if ($schema->hasTable($p . 'admin_permission')) {
            foreach (self::permissionUris($adminPrefix ?? GP247_ADMIN_PREFIX) as $slug => $uri) {
                if (!$db->table($p . 'admin_permission')->where('slug', $slug)->exists()) {
                    // insertOrIgnore: a site permission already using the same name wins, never an error.
                    $db->table($p . 'admin_permission')->insertOrIgnore([
                        'name' => self::PERMISSIONS[$slug], 'slug' => $slug, 'http_uri' => $uri, 'created_at' => $now,
                    ]);
                }
            }
        }

        if ($schema->hasTable($p . 'admin_menu') && !$db->table($p . 'admin_menu')->where('uri', self::MENU_URI)->exists()) {
            $db->table($p . 'admin_menu')->insert([
                'parent_id' => (int) $db->table($p . 'admin_menu')->where('key', self::PARENT_KEY)->value('id'),
                'sort' => 0,
                'title' => 'admin.menu_titles.backup',
                'icon' => 'fas fa-database',
                'uri' => self::MENU_URI,
                'key' => null,
                'type' => 0,
                'hidden' => 0,
            ]);
        }
    }

    /**
     * Remove the menu item and the permissions this area created (upgrade migration rollback).
     * Only permissions whose slug AND URI match the ones created here are removed — a site's own
     * permission that happens to reuse a slug stays. Role / user assignments of the removed
     * permissions go with them (a later up() recreates the permissions unassigned). The switch
     * row, the key ring and every archive are kept.
     *
     * @param ConnectionInterface $db
     * @param string|null         $adminPrefix Admin URL prefix (GP247_ADMIN_PREFIX by default).
     * @return void
     */
    public static function remove(ConnectionInterface $db, ?string $adminPrefix = null): void
    {
        $schema = $db->getSchemaBuilder();
        $p = GP247_DB_PREFIX;
        if ($schema->hasTable($p . 'admin_menu')) {
            $db->table($p . 'admin_menu')->where('uri', self::MENU_URI)->delete();
        }
        if (!$schema->hasTable($p . 'admin_permission')) {
            return;
        }
        $ids = [];
        $legacy = self::legacyPermissionUris($adminPrefix ?? GP247_ADMIN_PREFIX);
        foreach (self::permissionUris($adminPrefix ?? GP247_ADMIN_PREFIX) as $slug => $uri) {
            $id = $db->table($p . 'admin_permission')->where('slug', $slug)->whereIn('http_uri', [$uri, $legacy[$slug]])->value('id');
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        foreach (['admin_role_permission', 'admin_user_permission'] as $pivot) {
            if ($ids !== [] && $schema->hasTable($p . $pivot)) {
                $db->table($p . $pivot)->whereIn('permission_id', $ids)->delete();
            }
        }
        $db->table($p . 'admin_permission')->whereIn('id', $ids)->delete();
    }

    /**
     * Permission URIs (RBAC layer 2) — logical design backup-admin §2.
     *
     * @param string $prefix Admin URL prefix.
     * @return array<string, string>
     */
    public static function permissionUris(string $prefix): array
    {
        // WHY: download / restore also read the archive list (GET archives) — without it an admin
        // given only "download" or "restore" could not reach the archive they may act on. Every
        // action on that screen is checked again by BackupAccess (POST archives = create).
        // WHY ANY on download/*: a method-specific entry only matches the route URI template exactly
        // (download/{token}), never a wildcard; the route is GET only, so ANY grants nothing more.
        return [
            'backup.create' => 'ANY::' . $prefix . '/backup/archives,POST::' . $prefix . '/backup/tick',
            'backup.download' => 'GET::' . $prefix . '/backup/archives,ANY::' . $prefix . '/backup/download/*',
            'backup.restore' => 'GET::' . $prefix . '/backup/archives,ANY::' . $prefix . '/backup/restore*',
        ];
    }

    /**
     * URIs written by BK-1 (unreleased first cut), upgraded in place by showScreen().
     *
     * @param string $prefix
     * @return array<string, string>
     */
    public static function legacyPermissionUris(string $prefix): array
    {
        return [
            'backup.create' => 'ANY::' . $prefix . '/backup/archives,POST::' . $prefix . '/backup/tick',
            'backup.download' => 'GET::' . $prefix . '/backup/download/*',
            'backup.restore' => 'ANY::' . $prefix . '/backup/restore*',
        ];
    }

    /**
     * Sites that got the BK-1 area: show the menu item and widen the download / restore
     * permissions. Only rows still exactly as BK-1 wrote them change — a URI the site edited
     * is kept. Idempotent.
     *
     * @param ConnectionInterface $db
     * @param string|null         $adminPrefix
     * @return void
     */
    public static function showScreen(ConnectionInterface $db, ?string $adminPrefix = null): void
    {
        $schema = $db->getSchemaBuilder();
        $p = GP247_DB_PREFIX;
        $prefix = $adminPrefix ?? GP247_ADMIN_PREFIX;
        if ($schema->hasTable($p . 'admin_permission')) {
            $legacy = self::legacyPermissionUris($prefix);
            foreach (self::permissionUris($prefix) as $slug => $uri) {
                $db->table($p . 'admin_permission')->where('slug', $slug)->where('http_uri', $legacy[$slug])->update(['http_uri' => $uri]);
            }
        }
        self::setMenuVisible($db, BackupFeature::enabled());
    }

    /**
     * Show or hide the menu item (the switch hides it — ADR backup-engine_core-module-layout §3).
     *
     * @param ConnectionInterface $db
     * @param bool                $visible
     * @return void
     */
    public static function setMenuVisible(ConnectionInterface $db, bool $visible): void
    {
        if ($db->getSchemaBuilder()->hasTable(GP247_DB_PREFIX . 'admin_menu')) {
            $db->table(GP247_DB_PREFIX . 'admin_menu')->where('uri', self::MENU_URI)->update(['hidden' => $visible ? 0 : 1]);
        }
    }
}
