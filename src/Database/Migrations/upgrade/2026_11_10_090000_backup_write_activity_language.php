<?php

use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the message shown when a backup or restore cannot start because an extension is
 * writing site data in bulk (a data import), and the name extensions see for a running backup job.
 * No schema change. A fresh install gets the texts from the seeders.
 *
 * Idempotent: texts are inserted when missing. down() leaves them.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-write-activity-exclusion
 * @aidlc-adr backup-engine_write-activity-registry
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
