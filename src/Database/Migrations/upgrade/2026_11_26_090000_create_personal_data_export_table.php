<?php

use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: the table of customers' data packages (being built or waiting to be
 * downloaded). Creates only what is missing; touches no existing data.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package
 * @aidlc-adr personal-data-registry_erasure-job
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
     * Drops only the package table. WARNING: packages still waiting to be downloaded are lost, and
     * their files stay in storage/app/private/gp247/personal_data/exports — delete that folder by hand.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection(GP247_DB_CONNECTION)->dropIfExists(PersonalDataSchema::table(PersonalDataSchema::EXPORT));
    }
};
