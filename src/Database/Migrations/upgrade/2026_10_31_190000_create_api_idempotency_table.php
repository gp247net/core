<?php

use GP247\Core\Api\Sync\ApiSyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Table of stored admin API write results keyed by Idempotency-Key.
 * Creates only what is missing, so a second run — or a site installed with the table
 * already — changes nothing. Until this runs, writes that need a key answer 503.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-write-idempotency
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        ApiSyncSchema::create(Schema::connection(GP247_DB_CONNECTION));
    }

    /**
     * WARNING: drops the table. Stored write results are lost; a retry sent afterwards runs again.
     *
     * @return void
     */
    public function down()
    {
        ApiSyncSchema::drop(Schema::connection(GP247_DB_CONNECTION));
    }
};
