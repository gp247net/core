<?php

use GP247\Core\Support\Password\PasswordPolicyLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: add the language strings of the Password policy screen (administrator
 * block title, messages of a refused save). The policy values themselves are not touched —
 * an upgraded site keeps the administrator policy it has.
 *
 * Only inserts rows that do not exist yet (a site's edited translations are kept). Idempotent.
 * down() keeps the rows (removing them could drop a site's own translations).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-policy-audiences
 * @aidlc-adr admin-shell_password-policy-registry
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(PasswordPolicyLanguage::rows());
        }
    }

    /**
     * @return void
     */
    public function down()
    {
        //
    }
};
