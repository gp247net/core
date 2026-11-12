<?php

use GP247\Core\Support\Health\AlertLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: add the language strings of the "raw_output_scan" alert, which lists
 * {!! !!} prints in site templates/plugins now that text is stored as typed
 * (US-CLI-doctor-raw-output-scan).
 *
 * Only inserts rows that do not exist yet — a site's edited translations are kept.
 * Idempotent. down() keeps the rows (removing them could drop a site's own translations).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-doctor-raw-output-scan
 * @aidlc-adr compat-foundation_text-stored-as-typed
 */
return new class extends Migration
{
    /**
     * Insert the missing alert strings (insertOrIgnore keeps a site's edits).
     *
     * @return void
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
     */
    public function down()
    {
        // Language rows are kept on purpose (see class doc).
    }
};
