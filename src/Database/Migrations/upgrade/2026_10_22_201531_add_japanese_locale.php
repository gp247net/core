<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade: add Japanese (ja) to the language list of an installed
 * site, switched OFF, plus a Japanese store description copied from English.
 *
 * Switched off because enabling a language on a site that already has content
 * empties its /ja/ catalogue (descriptions are joined by language) and makes
 * every admin form require a Japanese name (RISK-BIZ-new-locale-empty-content);
 * the site owner turns it on once Japanese content exists. Fresh installs get ja
 * enabled from DataDefaultSeeder, so this migration does nothing while the
 * language table is still empty. The Japanese UI strings themselves arrive via
 * the DataLanguageSeeder re-run of gp247:core-update.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-japanese-default-locale
 * @aidlc-adr compat-foundation_locale-data-files
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $languages = $db->table(GP247_DB_PREFIX.'admin_language');
        if (!$languages->exists()) {
            return;
        }

        if (!$db->table(GP247_DB_PREFIX.'admin_language')->where('code', 'ja')->exists()) {
            // WHY no explicit id: a site may already use id 3 for its own language.
            $db->table(GP247_DB_PREFIX.'admin_language')->insert([
                'name' => '日本語',
                'code' => 'ja',
                'icon' => '/GP247/Core/language/flag_jp.png',
                'status' => 0,
                'rtl' => 0,
                'sort' => (int) $db->table(GP247_DB_PREFIX.'admin_language')->max('sort') + 1,
            ]);
        }

        $this->copyStoreDescriptions($db);
    }

    /**
     * Give every store without a ja description a copy of its English one
     * (or of its default language when it has no English description).
     *
     * @param \Illuminate\Database\ConnectionInterface $db
     * @return void
     */
    private function copyStoreDescriptions($db)
    {
        $table = GP247_DB_PREFIX.'admin_store_description';
        $stores = $db->table(GP247_DB_PREFIX.'admin_store')->get(['id', 'language']);
        foreach ($stores as $store) {
            $existing = $db->table($table)->where('store_id', $store->id)->pluck('lang')->all();
            if (in_array('ja', $existing, true)) {
                continue;
            }
            $source = in_array('en', $existing, true) ? 'en' : $store->language;
            $row = $db->table($table)->where('store_id', $store->id)->where('lang', $source)->first();
            if (!$row) {
                continue;
            }
            $copy = (array) $row;
            $copy['lang'] = 'ja';
            $db->table($table)->insertOrIgnore($copy);
        }
    }

    /**
     * WARNING: removes the ja language entry only while it is still switched off
     * (never one the site owner enabled), and the ja store descriptions. Japanese
     * UI strings are kept: they may hold the owner's edits and are inert without
     * the language.
     *
     * @return void
     */
    public function down()
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $removed = $db->table(GP247_DB_PREFIX.'admin_language')->where('code', 'ja')->where('status', 0)->delete();
        if ($removed) {
            $db->table(GP247_DB_PREFIX.'admin_store_description')->where('lang', 'ja')->delete();
        }
    }
};
