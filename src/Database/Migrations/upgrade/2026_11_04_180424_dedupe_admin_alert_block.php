<?php

use GP247\Core\AdminShell\Support\AdminAlertBlock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: remove the duplicate "System alerts" dashboard blocks that core 3.10.0
 * added on each `gp247:update` (the seeder inserted the row without a key).
 *
 * Deletes only rows of the alerts view other than the oldest one (lowest id); the copies
 * are identical, so no setting is lost. Idempotent: with one row or none it does nothing.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'admin_home')) {
            AdminAlertBlock::dedupe(DB::connection(GP247_DB_CONNECTION));
        }
    }

    /**
     * Irreversible on purpose: the removed rows were accidental copies; recreating them
     * would bring the duplicate blocks back.
     *
     * @return void
     */
    public function down()
    {
        // irreversible — nothing to restore.
    }
};
