<?php

namespace GP247\Core\Api\Sync;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tables of the API sync features, shared by the fresh-install migration and the upgrade
 * migration so both build the same schema. Creating is idempotent.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-write-idempotency, US-api-sync-event-record
 * @aidlc-adr api-sync_admin-idempotency-store, api-sync_event-log-after-commit
 */
final class ApiSyncSchema
{
    /**
     * Create the idempotency-key and event-log tables when they are missing.
     *
     * @param Builder $schema
     * @return void
     */
    public static function create(Builder $schema): void
    {
        if (!$schema->hasTable(GP247_DB_PREFIX . 'api_idempotency')) {
            $schema->create(GP247_DB_PREFIX . 'api_idempotency', function (Blueprint $table) {
                $table->id();
                $table->string('scope', 100);
                $table->char('key_hash', 64);
                $table->char('request_hash', 64);
                $table->string('status', 10);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->mediumText('response_body')->nullable();
                $table->timestamps();
                $table->unique(['scope', 'key_hash']);
                $table->index('created_at');
            });
        }
        if (!$schema->hasTable(GP247_DB_PREFIX . 'api_event')) {
            $schema->create(GP247_DB_PREFIX . 'api_event', function (Blueprint $table) {
                $table->id();
                $table->string('type', 64);
                $table->string('subject_id', 100);
                $table->string('store_id', 50)->nullable();
                $table->string('source', 10);
                $table->string('actor_type', 10)->nullable();
                $table->string('actor_id', 100)->nullable();
                $table->text('data')->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->index(['store_id', 'id']);
                $table->index('occurred_at');
            });
        }
    }

    /**
     * Drop both tables (fresh-install rollback).
     *
     * @param Builder $schema
     * @return void
     */
    public static function drop(Builder $schema): void
    {
        self::dropIdempotency($schema);
        self::dropEventLog($schema);
    }

    /**
     * Drop the idempotency-key table (stored results are lost; retries then run again).
     *
     * @param Builder $schema
     * @return void
     */
    public static function dropIdempotency(Builder $schema): void
    {
        $schema->dropIfExists(GP247_DB_PREFIX . 'api_idempotency');
    }

    /**
     * Drop the event-log table (every recorded event is lost; readers must resync).
     *
     * @param Builder $schema
     * @return void
     */
    public static function dropEventLog(Builder $schema): void
    {
        $schema->dropIfExists(GP247_DB_PREFIX . 'api_event');
    }
}
