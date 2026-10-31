<?php

namespace GP247\Core\Api\Sync;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tables of the API sync features, shared by the fresh-install migration and the upgrade
 * migration so both build the same schema. Creating is idempotent.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-write-idempotency
 * @aidlc-adr api-sync_admin-idempotency-store
 */
final class ApiSyncSchema
{
    /**
     * Create the idempotency-key table when it is missing.
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
    }

    /**
     * Drop the idempotency-key table (stored results are lost; retries then run again).
     *
     * @param Builder $schema
     * @return void
     */
    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(GP247_DB_PREFIX . 'api_idempotency');
    }
}
