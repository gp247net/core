<?php

use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the table that records where each retention item stands (first clean allowed,
 * last run, rows handled). Creates only what is missing; touches no existing data.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-lazy-run
 * @aidlc-adr personal-data-retention_sweep-runner
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
     * Drops only the state table: the next run counts again from the first time each item is seen,
     * so nothing is cleaned for 30 days. Settings stay in admin_config.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(GP247_DB_CONNECTION)->dropIfExists(PersonalDataSchema::table(PersonalDataSchema::RETENTION));
    }
};
