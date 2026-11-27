<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the "Webhook" screen sends error reports to Slack, Google Chat and a new
 * third-party webhook field. Removes the Chatwork URL setting (Chatwork has no incoming
 * webhook; no code ever read it) with a WHERE on its key, and adds the empty third-party
 * setting when missing. Labels come from DataLanguageSeeder (gp247:core-update). A fresh
 * install seeds the same three settings, so both end alike.
 *
 * Idempotent. Irreversible: the removed Chatwork value never had any effect, down()
 * restores nothing.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-report-channels-env, US-CMP-fresh-install-upgrade-equivalence
 * @aidlc-adr compat-foundation_report-channels-env
 */
return new class extends Migration
{
    /**
     * Drop the Chatwork setting and add the third-party one.
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

        $db->table($table)
            ->where('group', 'global')
            ->where('key', 'CHATWORK_CHAT_WEBHOOK_URL')
            ->delete();

        $db->table($table)->insertOrIgnore([
            'group' => 'global',
            'code' => 'webhook_config',
            'key' => 'THIRD_PARTY_WEBHOOK_URL',
            'value' => '',
            'sort' => 0,
            'detail' => 'admin.config.THIRD_PARTY_WEBHOOK_URL',
            'store_id' => (string) GP247_STORE_ID_GLOBAL,
        ]);
    }

    /**
     * Irreversible: the removed Chatwork value never had any effect, nothing to restore.
     *
     * @return void
     */
    public function down(): void
    {
        // Irreversible on purpose (see class doc).
    }
};
