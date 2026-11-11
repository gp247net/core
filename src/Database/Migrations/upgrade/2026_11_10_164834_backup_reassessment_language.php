<?php

use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the texts of the backup re-assessment (mod 20261110T164834) — what a restore does not
 * bring back, columns whose data would be lost, the newest full backup, an untrusted key ring and the
 * new refusals. No schema change; a fresh install gets the texts from the seeders.
 *
 * insertOrIgnore: a text the site already has (or edited) is kept. Idempotent. down() leaves the texts.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-version-compatibility, US-backup-engine-retention, US-backup-admin-step-up-auth
 * @aidlc-adr backup-restore_shadow-table-swap
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
