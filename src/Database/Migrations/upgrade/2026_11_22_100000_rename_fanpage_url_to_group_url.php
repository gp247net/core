<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom config social links: the `fanpage_url` key becomes `group_url` (a Facebook
 * group, shown next to the Facebook icon in the storefront footer), and the seeded
 * default links move from the GP247 page to the S-Cart page and group.
 *
 * - Each store's `fanpage_url` row is renamed to `group_url`, keeping the owner's value,
 *   unless that store already has a `group_url` row (then nothing is touched).
 * - A row whose label still points at the old language code gets the new one.
 * - `facebook_url` / `group_url` are replaced only while they still hold exactly the old
 *   seeded default — a link the owner set is kept.
 *
 * Idempotent: a second run finds no `fanpage_url` row and no old default left.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 */
return new class extends Migration
{
    /** The value the old DataStoreSeeder wrote for both facebook_url and fanpage_url. */
    private const OLD_DEFAULT = 'https://www.facebook.com/GP247.official/';

    /** New defaults, same as DataStoreSeeder. */
    private const NEW_FACEBOOK = 'https://www.facebook.com/SCart.Ecommerce/';
    private const NEW_GROUP = 'https://www.facebook.com/groups/scart.opensource';

    private const CODE = 'admin_custom_config';

    /**
     * Rename fanpage_url to group_url per store and refresh untouched defaults.
     *
     * @return void
     */
    public function up(): void
    {
        $table = GP247_DB_PREFIX . 'admin_config';
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable($table)) {
            return;
        }
        $db = DB::connection(GP247_DB_CONNECTION);

        // Stores that already have a group_url keep their fanpage_url as it is (no clash
        // on the per-store key).
        $storesWithGroup = $db->table($table)
            ->where('code', self::CODE)
            ->where('key', 'group_url')
            ->pluck('store_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $rename = $db->table($table)
            ->where('code', self::CODE)
            ->where('key', 'fanpage_url');
        if ($storesWithGroup !== []) {
            $rename->whereNotIn('store_id', $storesWithGroup);
        }
        $rename->update(['key' => 'group_url']);

        $db->table($table)
            ->where('code', self::CODE)
            ->where('key', 'group_url')
            ->where('detail', 'admin.admin_custom_config.fanpage_url')
            ->update(['detail' => 'admin.admin_custom_config.group_url']);

        $db->table($table)
            ->where('code', self::CODE)
            ->where('key', 'facebook_url')
            ->where('value', self::OLD_DEFAULT)
            ->update(['value' => self::NEW_FACEBOOK]);

        $db->table($table)
            ->where('code', self::CODE)
            ->where('key', 'group_url')
            ->where('value', self::OLD_DEFAULT)
            ->update(['value' => self::NEW_GROUP]);
    }

    /**
     * Irreversible on purpose: after the rename an owner may have added a new
     * `fanpage_url` of their own, and the old default only pointed at the GP247 page,
     * so renaming back or restoring it could overwrite what the owner set.
     *
     * @return void
     */
    public function down(): void
    {
    }
};
