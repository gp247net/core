<?php

use GP247\Core\Api\Support\ApiSessionSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Tables of REST API v2 sessions and rotating refresh tokens.
 * Creates only what is missing, so a second run — or a site installed with the tables
 * already — changes nothing. Until this runs, the API keeps working without sessions.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-session-management, US-CMP-api-refresh-token-rotation
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        ApiSessionSchema::create(Schema::connection(GP247_DB_CONNECTION));
    }

    /**
     * WARNING: drops both tables. API v2 sessions can no longer refresh or be listed;
     * access tokens already issued keep working until they expire.
     *
     * @return void
     */
    public function down()
    {
        ApiSessionSchema::drop(Schema::connection(GP247_DB_CONNECTION));
    }
};
