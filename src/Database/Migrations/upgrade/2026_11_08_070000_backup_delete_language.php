<?php

use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the message shown when a backup cannot be deleted because its files could not be
 * removed from the backup folder (the backup then stays listed). No schema change. A fresh install
 * gets the text from the seeders.
 *
 * Idempotent: texts are inserted when missing. down() leaves them.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-cli, US-backup-admin-dashboard
 * @aidlc-adr backup-engine_core-module-layout
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(BackupLanguage::rows());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // Intentionally empty: texts are harmless and may have been edited by the site.
    }
};
