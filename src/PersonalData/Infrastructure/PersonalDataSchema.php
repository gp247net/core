<?php

namespace GP247\Core\PersonalData\Infrastructure;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tables of the personal data feature, shared by the fresh-install migration and the upgrade
 * migration so both build the same schema. Creating is idempotent.
 *
 *  - personal_data_job:            erasure jobs run in slices (temporary, never backed up);
 *  - personal_data_erasure:        the erasure ledger (backed up, never replaced by a restore);
 *  - personal_data_erasure_email:  salted hashes of the erased emails, one row per address.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erasure-ledger, US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_module-layout
 */
final class PersonalDataSchema
{
    public const JOB = 'personal_data_job';
    public const ERASURE = 'personal_data_erasure';
    public const ERASURE_EMAIL = 'personal_data_erasure_email';

    /**
     * @param string $logical
     * @return string Real table name.
     */
    public static function table(string $logical): string
    {
        return GP247_DB_PREFIX . $logical;
    }

    /**
     * Create the tables that are missing.
     *
     * @param Builder $schema
     * @return void
     */
    public static function create(Builder $schema): void
    {
        if (!$schema->hasTable(self::table(self::JOB))) {
            $schema->create(self::table(self::JOB), function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->string('kind', 20);
                $table->string('status', 20);
                $table->string('subject_customer_id', 64)->nullable();
                $table->string('origin', 20);
                $table->string('actor_guard', 20)->nullable();
                $table->string('actor_id', 64)->nullable();
                $table->string('erasure_id', 26)->nullable();
                $table->longText('cursor')->nullable();
                $table->string('lease_owner', 40)->nullable();
                $table->timestamp('lease_until')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('error', 500)->nullable();
                $table->timestamps();
                $table->index(['status', 'lease_until'], 'pdj_status_idx');
            });
        }
        if (!$schema->hasTable(self::table(self::ERASURE))) {
            $schema->create(self::table(self::ERASURE), function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->string('customer_id', 64)->nullable();
                $table->string('origin', 20);
                $table->string('job_id', 26)->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('outcome')->nullable();
                $table->index('customer_id', 'pde_customer_idx');
                $table->index('requested_at', 'pde_requested_idx');
            });
        }
        if (!$schema->hasTable(self::table(self::ERASURE_EMAIL))) {
            $schema->create(self::table(self::ERASURE_EMAIL), function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('erasure_id', 26);
                $table->char('email_hash', 64);
                $table->unique(['erasure_id', 'email_hash'], 'pdee_entry_hash_uq');
                $table->index('email_hash', 'pdee_hash_idx');
            });
        }
    }

    /**
     * WARNING: drops the tables — the erasure ledger is lost, so a later restore of an older
     * backup can no longer erase again the customers erased since.
     *
     * @param Builder $schema
     * @return void
     */
    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(self::table(self::ERASURE_EMAIL));
        $schema->dropIfExists(self::table(self::ERASURE));
        $schema->dropIfExists(self::table(self::JOB));
    }
}
