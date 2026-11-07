<?php

use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: texts of Bolt BK-7 — the "Schedule" block of the backup settings, the recovery
 * point on the backup screen, the "too old" e-mail, the answers of the "before change" snapshot and
 * the "backup folder reachable over the web" alert. No schema change (the schedule keys default when
 * missing in admin_config). A fresh install gets the texts from the seeders.
 *
 * Idempotent: texts are inserted when missing. down() leaves them.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule, US-backup-admin-doctor-checks, US-backup-engine-pre-change-snapshot
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
