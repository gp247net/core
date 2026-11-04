<?php

use GP247\Core\Support\PreChange\PreChangeLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: add the language strings of the "before change" seam (stop messages, the
 * "continue without a snapshot" confirmation on extension update and template switch).
 *
 * Only inserts rows that do not exist yet (a site's edited translations are kept). Idempotent.
 * down() keeps the rows (removing them could drop a site's own translations).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-pre-change-hook, US-PLG-pre-change-hook
 * @aidlc-adr system-cli_pre-change-registry
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(PreChangeLanguage::rows());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        //
    }
};
