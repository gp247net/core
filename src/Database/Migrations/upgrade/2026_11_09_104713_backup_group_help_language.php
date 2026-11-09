<?php

use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the descriptions of the backup groups the core owns (configuration, admin accounts,
 * logs, file groups), the generic description of an extension's group and the "Other tables" note of
 * the full backup. No schema change; a fresh install gets the texts from the seeders.
 *
 * insertOrIgnore: a text the site already has (or edited) is kept. Idempotent. down() leaves the texts.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard, US-backup-engine-data-groups
 * @aidlc-adr backup-engine_data-group-registry
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            return;
        }
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(BackupLanguage::rows());
    }

    /**
     * @return void
     */
    public function down()
    {
        // Intentionally empty: texts are harmless and may have been edited by the site.
    }
};
