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
 *  - personal_data_erasure_email:  salted hashes of the erased emails, one row per address;
 *  - personal_data_request:        export / erase requests and where they stand (never replaced by a restore);
 *  - personal_data_request_log:    append-only history of every request (never replaced by a restore);
 *  - personal_data_export:         data packages being built or waiting to be downloaded (temporary,
 *                                  the file is removed once downloaded or after 24 hours);
 *  - personal_data_retention:      where each retention item stands (first clean allowed, last run, rows); no customer data.
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
    public const REQUEST = 'personal_data_request';
    public const REQUEST_LOG = 'personal_data_request_log';
    public const EXPORT = 'personal_data_export';
    public const RETENTION = 'personal_data_retention';

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
        if (!$schema->hasTable(self::table(self::REQUEST))) {
            $schema->create(self::table(self::REQUEST), function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->string('kind', 20);
                $table->string('source', 20);
                $table->string('channel', 20)->nullable();
                $table->string('status', 30);
                $table->string('customer_id', 64)->nullable();
                $table->char('email_hash', 64)->nullable();
                $table->text('email_enc')->nullable();
                $table->string('store_id', 64)->default('1');
                $table->timestamp('received_at')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('wait_until')->nullable();
                $table->timestamp('next_check_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->string('erasure_id', 26)->nullable();
                $table->string('job_id', 26)->nullable();
                $table->string('created_by', 64)->nullable();
                $table->text('note')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['status', 'due_at'], 'pdr_status_due_idx');
                $table->index('customer_id', 'pdr_customer_idx');
                $table->index('store_id', 'pdr_store_idx');
                $table->index('erasure_id', 'pdr_erasure_idx');
            });
        }
        if (!$schema->hasTable(self::table(self::REQUEST_LOG))) {
            $schema->create(self::table(self::REQUEST_LOG), function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->string('request_id', 26)->nullable();
                $table->string('event', 40);
                $table->string('actor_type', 20);
                $table->string('actor_id', 64)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('reason', 500)->nullable();
                $table->text('context')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index('request_id', 'pdrl_request_idx');
                $table->index('created_at', 'pdrl_created_idx');
            });
        }
        if (!$schema->hasTable(self::table(self::EXPORT))) {
            $schema->create(self::table(self::EXPORT), function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->string('request_id', 26)->nullable();
                $table->string('customer_id', 64)->nullable();
                $table->string('job_id', 26)->nullable();
                $table->string('audience', 10)->default('customer');
                $table->string('status', 20);
                $table->unsignedSmallInteger('downloads')->default(0);
                $table->unsignedBigInteger('file_size')->nullable();
                $table->char('token_hash', 64)->nullable();
                $table->string('token_owner', 80)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('downloaded_at')->nullable();
                $table->timestamp('cleaned_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index(['customer_id', 'created_at'], 'pdx_customer_idx');
                $table->index(['status', 'expires_at'], 'pdx_status_idx');
            });
        }
        if (!$schema->hasTable(self::table(self::RETENTION))) {
            $schema->create(self::table(self::RETENTION), function (Blueprint $table) {
                $table->string('item_key', 40)->primary();
                $table->timestamp('first_allowed_at')->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->unsignedInteger('last_rows')->default(0);
                $table->unsignedBigInteger('total_rows')->default(0);
                $table->string('last_error', 255)->nullable();
                $table->unsignedSmallInteger('errors')->default(0);
                $table->string('cursor', 100)->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    /**
     * WARNING: drops the tables — the erasure ledger and the request history are lost, so a later restore of an older
     * backup can no longer erase again the customers erased since.
     *
     * @param Builder $schema
     * @return void
     */
    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(self::table(self::RETENTION));
        $schema->dropIfExists(self::table(self::EXPORT));
        $schema->dropIfExists(self::table(self::REQUEST_LOG));
        $schema->dropIfExists(self::table(self::REQUEST));
        $schema->dropIfExists(self::table(self::ERASURE_EMAIL));
        $schema->dropIfExists(self::table(self::ERASURE));
        $schema->dropIfExists(self::table(self::JOB));
    }
}
