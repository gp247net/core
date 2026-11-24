<?php

use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the tables of the "Personal data requests" screen — the requests and their
 * append-only history. Creates only what is missing; touches no existing data. Permission, menu
 * and default settings arrive through the seeder that gp247:update runs.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-install-lifecycle
 * @aidlc-adr personal-data-requests_request-model, personal-data-requests_request-log
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        PersonalDataSchema::create(Schema::connection(GP247_DB_CONNECTION));
    }

    /**
     * WARNING: drops the request history — the evidence that requests were handled on time.
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $schema->dropIfExists(PersonalDataSchema::table(PersonalDataSchema::REQUEST_LOG));
        $schema->dropIfExists(PersonalDataSchema::table(PersonalDataSchema::REQUEST));
    }
};
