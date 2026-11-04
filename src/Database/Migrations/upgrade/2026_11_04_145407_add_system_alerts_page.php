<?php

use GP247\Core\AdminShell\Support\SystemAlertsMenu;
use GP247\Core\Support\Health\AlertLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: add the "System alerts" menu item in the "Security" group and the new
 * language strings of the compact block and the page (US-admin-shell-system-alerts-page).
 *
 * Only inserts — the menu item when no row has its uri, language rows that do not exist yet
 * (a site's edited translations are kept). Idempotent. down() removes the menu item and keeps
 * the language rows (removing them could drop a site's own translations).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts-page
 * @aidlc-adr admin-shell_system-alerts-page
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

        if ($schema->hasTable(GP247_DB_PREFIX . 'admin_menu')) {
            SystemAlertsMenu::ensure($db);
        }

        if ($schema->hasTable(GP247_DB_PREFIX . 'languages')) {
            $db->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(AlertLanguage::rows());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'admin_menu')) {
            SystemAlertsMenu::remove(DB::connection(GP247_DB_CONNECTION));
        }
    }
};
