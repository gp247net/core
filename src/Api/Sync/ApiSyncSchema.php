<?php

namespace GP247\Core\Api\Sync;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tables of the API sync features, shared by the fresh-install migration and the upgrade
 * migration so both build the same schema. Creating is idempotent.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-write-idempotency, US-api-sync-event-record, US-api-sync-webhook-register
 * @aidlc-adr api-sync_admin-idempotency-store, api-sync_event-log-after-commit, api-sync_webhook-deferred-delivery
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
        if (!$schema->hasTable(GP247_DB_PREFIX . 'api_webhook')) {
            $schema->create(GP247_DB_PREFIX . 'api_webhook', function (Blueprint $table) {
                $table->id();
                $table->string('url', 500);
                $table->text('event_types');
                $table->text('secret');
                $table->tinyInteger('status')->default(1);
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->string('disabled_reason', 255)->nullable();
                $table->timestamps();
            });
        }
        if (!$schema->hasTable(GP247_DB_PREFIX . 'api_webhook_delivery')) {
            $schema->create(GP247_DB_PREFIX . 'api_webhook_delivery', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('webhook_id');
                $table->unsignedBigInteger('event_id');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->string('status', 10);
                $table->timestamp('next_attempt_at')->nullable();
                $table->unsignedSmallInteger('last_status_code')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
                $table->index(['status', 'next_attempt_at']);
                $table->index(['webhook_id', 'id']);
                $table->index('updated_at');
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
        self::dropWebhooks($schema);
    }

    /**
     * Drop the webhook tables (registrations, signing secrets and delivery history are lost).
     *
     * @param Builder $schema
     * @return void
     */
    public static function dropWebhooks(Builder $schema): void
    {
        $schema->dropIfExists(GP247_DB_PREFIX . 'api_webhook_delivery');
        $schema->dropIfExists(GP247_DB_PREFIX . 'api_webhook');
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
