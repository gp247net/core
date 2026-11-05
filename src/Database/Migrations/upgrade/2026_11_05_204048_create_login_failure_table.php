<?php

use GP247\Core\AdminShell\Support\LoginFailuresMenu;
use GP247\Core\Support\LoginFailure\LoginFailureLanguage;
use GP247\Core\Support\LoginFailure\LoginFailureSchema;
use GP247\Core\Support\LoginFailure\LoginFailureSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the failed sign-in log — its table, the "Login failures" menu item in the
 * "Security" group, the setting that records administrators only, and its language strings.
 *
 * Creates / inserts only what is missing (a site's own setting and edited translations are
 * kept). Idempotent. Until this runs, sign-ins work as before and nothing is recorded.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-log, US-admin-shell-login-failure-list
 * @aidlc-adr admin-shell_login-failure-log
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

        LoginFailureSchema::create($schema);
        if ($schema->hasTable(GP247_DB_PREFIX . 'admin_menu')) {
            LoginFailuresMenu::ensure($db);
        }
        if ($schema->hasTable(GP247_DB_PREFIX . 'admin_config')) {
            $db->table(GP247_DB_PREFIX . 'admin_config')->insertOrIgnore([LoginFailureSettings::defaultRow()]);
        }
        if ($schema->hasTable(GP247_DB_PREFIX . 'languages')) {
            $db->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(LoginFailureLanguage::rows());
        }
    }

    /**
     * WARNING: drops the table — every recorded failed sign-in is lost. The menu item goes too;
     * the setting row and language rows are kept.
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        if ($schema->hasTable(GP247_DB_PREFIX . 'admin_menu')) {
            LoginFailuresMenu::remove(DB::connection(GP247_DB_CONNECTION));
        }
        LoginFailureSchema::drop($schema);
    }
};
