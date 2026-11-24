<?php

use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the tables of personal data erasure — the jobs that erase a customer in
 * slices and the erasure ledger (who was erased, when, by whom; only salted hashes of emails).
 * Creates only what is missing; touches no existing data. Labels arrive through the core
 * language seeder that gp247:update runs.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erasure-ledger, US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_module-layout
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
     * WARNING: drops the erasure ledger — a later restore of an older backup can no longer
     * erase again the customers erased since. Unfinished erasure jobs are lost too.
     *
     * @return void
     */
    public function down()
    {
        PersonalDataSchema::drop(Schema::connection(GP247_DB_CONNECTION));
    }
};
