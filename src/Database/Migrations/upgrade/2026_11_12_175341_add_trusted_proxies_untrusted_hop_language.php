<?php

use GP247\Core\Support\Health\AlertLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: add the language strings of the "trusted_proxies" warning for a
 * proxy missing from TRUSTED_PROXIES (US-admin-shell-system-alerts).
 *
 * Only inserts rows that do not exist yet — a site's edited translations are kept.
 * Idempotent. down() keeps the rows (removing them could drop a site's own translations).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
return new class extends Migration
{
    /**
     * Insert the missing alert strings (insertOrIgnore keeps a site's edits).
     *
     * @return void
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    public function up()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(AlertLanguage::rows());
        }
    }

    /**
     * Keep the rows: removing them could drop a site's own translations.
     *
     * @return void
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    public function down()
    {
        // Language rows are kept on purpose (see class doc).
    }
};
