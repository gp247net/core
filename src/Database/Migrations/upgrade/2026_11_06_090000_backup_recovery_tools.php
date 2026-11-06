<?php

use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: recovery tools of the backup area (Bolt BK-5) — the "choose a new password"
 * flag a post-incident restore sets on every admin, and the texts of the upload, unverified-origin
 * and post-incident parts of the screens. A fresh install gets the column from the base migration
 * and the texts from the seeders.
 *
 * Idempotent: the column is added only when missing, texts are inserted when missing. down()
 * leaves both (dropping the column would silently lift a flag a restore set).
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-post-incident-mode, US-backup-restore-import-archive
 * @aidlc-adr backup-engine_core-module-layout
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'admin_user';
        if ($schema->hasTable($table) && !$schema->hasColumn($table, 'password_change_required')) {
            $schema->table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('password_change_required')->default(false);
            });
        }
        if ($schema->hasTable(GP247_DB_PREFIX . 'languages')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(BackupLanguage::rows());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        // Intentionally empty: see the class comment.
    }
};
