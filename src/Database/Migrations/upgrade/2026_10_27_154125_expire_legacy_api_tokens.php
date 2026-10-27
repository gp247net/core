<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give an expiry to the API tokens issued before tokens had one (expires_at NULL —
 * issued before 3.2.3): their real age plus the "remember me" lifetime of their owner
 * (admin or customer, as login issues them), but never sooner than GRACE_DAYS after the
 * upgrade, so apps holding an old token are not signed out the moment the site is
 * updated (DQ1, mod 20261027T154124). Tokens that already expire are not touched; a
 * second run finds nothing to do.
 *
 * Computed in PHP, not SQL, because MySQL/MariaDB/SQLite spell date arithmetic
 * differently.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-token-lifetime
 */
return new class extends Migration
{
    /** Minimum time a legacy token keeps working after the upgrade. */
    private const GRACE_DAYS = 7;

    /** expires_at is a TIMESTAMP column on MySQL (max 2038-01-19): never write past it. */
    private const LATEST = '2037-12-31 23:59:59';

    /**
     * @return void
     */
    public function up()
    {
        $connection = DB::connection(GP247_DB_CONNECTION);
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable('personal_access_tokens')) {
            return;
        }

        $adminDays = (int) config('gp247-config.api.auth.api_remember_admin', 30);
        $customerDays = (int) config('gp247-config.api.auth.api_remember', 30);
        $floor = Carbon::now()->addDays(self::GRACE_DAYS);
        $ceiling = Carbon::parse(self::LATEST);

        $connection->table('personal_access_tokens')
            ->whereNull('expires_at')
            ->select(['id', 'tokenable_type', 'created_at'])
            ->chunkById(500, function ($rows) use ($connection, $adminDays, $customerDays, $floor, $ceiling) {
                foreach ($rows as $row) {
                    $days = $row->tokenable_type === \GP247\Core\Models\AdminUser::class ? $adminDays : $customerDays;
                    $issued = $row->created_at ? Carbon::parse($row->created_at) : Carbon::now();
                    $expiry = $issued->copy()->addDays($days);
                    $expiry = $expiry->greaterThan($floor) ? $expiry : $floor;
                    $connection->table('personal_access_tokens')
                        ->where('id', $row->id)
                        ->whereNull('expires_at')
                        ->update(['expires_at' => $expiry->greaterThan($ceiling) ? $ceiling : $expiry]);
                }
            });
    }

    /**
     * Irreversible: which tokens had no expiry before is not recorded, so nothing is
     * undone (a token with an expiry is the intended state).
     *
     * @return void
     */
    public function down()
    {
        //
    }
};
