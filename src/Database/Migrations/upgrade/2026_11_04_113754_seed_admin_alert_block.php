<?php

use GP247\Core\Support\Health\AlertLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: add the "System alerts" dashboard block and its language rows.
 *
 * Idempotent: the block row is added only when no row with the same view exists
 * (an owner who disabled or moved it keeps that choice); language rows use
 * insertOrIgnore so a translation the site already edited is kept.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-AUI-005
 * @aidlc-adr admin-shell_system-alert-registry
 */
return new class extends Migration
{
    private const VIEW = 'gp247-admin::component.alerts';
    private const SORT = 1000;

    /**
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $db = DB::connection(GP247_DB_CONNECTION);

        if ($schema->hasTable(GP247_DB_PREFIX . 'admin_home')
            && !$db->table(GP247_DB_PREFIX . 'admin_home')->where('view', self::VIEW)->exists()) {
            // WHY max+1: stay above blocks a site already sorted higher than the default 1000.
            $sort = max(self::SORT, (int) $db->table(GP247_DB_PREFIX . 'admin_home')->max('sort') + 1);
            $db->table(GP247_DB_PREFIX . 'admin_home')->insert([
                'view' => self::VIEW,
                'size' => 12,
                'sort' => $sort,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($schema->hasTable(GP247_DB_PREFIX . 'languages')) {
            $db->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(AlertLanguage::rows());
        }
    }

    /**
     * Remove the block row this feature adds. Language rows are left in place (a missing
     * label only shows its code; deleting could drop a translation the site edited).
     *
     * @return void
     */
    public function down()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'admin_home')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_home')->where('view', self::VIEW)->delete();
        }
    }
};
