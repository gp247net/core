<?php

use GP247\Core\Backup\Infrastructure\Install\BackupInstaller;
use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use GP247\Core\Backup\Infrastructure\Install\BackupSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: create the backup area of core — its tables, the on/off switch (on), the
 * create / download / restore permissions, the menu item (hidden until the backup screen
 * ships) and its language rows. A fresh install gets the same through the step1 migration
 * and the seeders.
 *
 * Only creates / inserts what is missing; idempotent. down() removes the menu item and
 * permissions and drops only the backup tables that are empty — archives and the backup key
 * ring are never deleted.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-install-lifecycle
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
        $db = DB::connection(GP247_DB_CONNECTION);

        BackupSchema::create($schema);
        BackupInstaller::ensure($db);

        if ($schema->hasTable(GP247_DB_PREFIX . 'languages')) {
            $db->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(BackupLanguage::rows());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        BackupInstaller::remove($db);
        BackupSchema::dropIfEmpty(Schema::connection(GP247_DB_CONNECTION), $db);
    }
};
