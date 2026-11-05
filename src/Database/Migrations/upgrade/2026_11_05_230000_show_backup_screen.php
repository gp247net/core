<?php

use GP247\Core\Backup\Infrastructure\Install\BackupInstaller;
use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the backup screen ships (Bolt BK-4). Shows the "Backup & restore" menu item
 * (created hidden by 2026_11_05_090000), lets the download / restore permissions read the archive
 * list, and adds the screen and e-mail texts. A fresh install gets the same through the seeders.
 *
 * Idempotent: only permission rows still exactly as the first migration wrote them change, and
 * language rows are inserted when missing. down() hides the menu item and puts the first URIs
 * back; texts stay (harmless).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-install-lifecycle, US-backup-admin-dashboard
 * @aidlc-adr backup-engine_core-module-layout
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $schema = Schema::connection(GP247_DB_CONNECTION);
        if (!$schema->hasTable(GP247_DB_PREFIX . 'backup_archive')) {
            // The area itself is created by 2026_11_05_090000; nothing to show without it.
            return;
        }
        BackupInstaller::showScreen($db);
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
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'admin_permission')) {
            return;
        }
        $legacy = BackupInstaller::legacyPermissionUris(GP247_ADMIN_PREFIX);
        foreach (BackupInstaller::permissionUris(GP247_ADMIN_PREFIX) as $slug => $uri) {
            $db->table(GP247_DB_PREFIX . 'admin_permission')->where('slug', $slug)->where('http_uri', $uri)->update(['http_uri' => $legacy[$slug]]);
        }
        BackupInstaller::setMenuVisible($db, false);
    }
};
