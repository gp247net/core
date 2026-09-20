<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade: seed the two labels of the operation-log "detail" column
 * (what an action did, and the toggle for its full input) for sites installed
 * before US-admin-shell-livewire-operation-log. Fresh installs get them from
 * DataLanguageSeeder; insertOrIgnore keeps any text a site owner already edited.
 *
 * Runs via gp247:core-update (--path upgrade/), never the create-tables migration.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-admin-shell-livewire-operation-log
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX.'languages')
            ->insertOrIgnore([
                ['code' => 'admin.log.detail',     'text' => 'Thao tác',      'position' => 'admin.log', 'location' => 'vi'],
                ['code' => 'admin.log.detail',     'text' => 'Action detail', 'position' => 'admin.log', 'location' => 'en'],
                ['code' => 'admin.log.view_input', 'text' => 'Xem dữ liệu',   'position' => 'admin.log', 'location' => 'vi'],
                ['code' => 'admin.log.view_input', 'text' => 'View input',    'position' => 'admin.log', 'location' => 'en'],
            ]);
    }

    /**
     * @return void
     */
    public function down()
    {
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX.'languages')
            ->whereIn('code', ['admin.log.detail', 'admin.log.view_input'])
            ->delete();
    }
};
