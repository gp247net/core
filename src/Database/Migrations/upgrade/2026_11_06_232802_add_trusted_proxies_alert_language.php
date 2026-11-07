<?php

use GP247\Core\Support\Health\AlertLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: add the language strings of the "trusted_proxies" system alert
 * (US-admin-shell-system-alerts).
 *
 * Only inserts rows that do not exist yet — a site's edited translations are kept.
 * Idempotent. down() keeps the rows (removing them could drop a site's own translations).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(AlertLanguage::rows());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // Language rows are kept on purpose (see class doc).
    }
};
