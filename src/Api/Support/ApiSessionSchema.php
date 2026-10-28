<?php

namespace GP247\Core\Api\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tables of REST API v2 sessions, shared by the fresh-install migration and the
 * upgrade migration so both build the same schema. Creating is idempotent.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-session-management, US-CMP-api-refresh-token-rotation
 * @aidlc-adr compat-foundation_api-token-rotation
 */
final class ApiSessionSchema
{
    /**
     * Create the session and refresh-token tables when they are missing.
     *
     * @param Builder $schema
     * @return void
     */
    public static function create(Builder $schema): void
    {
        if (!$schema->hasTable(GP247_DB_PREFIX . 'api_session')) {
            $schema->create(GP247_DB_PREFIX . 'api_session', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tokenable_type');
                $table->uuid('tokenable_id');
                $table->string('audience', 16);
                $table->string('name', 100)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->text('abilities')->nullable();
                $table->unsignedBigInteger('access_token_id')->nullable()->index();
                $table->boolean('refreshable')->default(false);
                $table->timestamp('refresh_expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
                $table->index(['tokenable_type', 'tokenable_id']);
            });
        }
        if (!$schema->hasTable(GP247_DB_PREFIX . 'api_refresh_token')) {
            $schema->create(GP247_DB_PREFIX . 'api_refresh_token', function (Blueprint $table) {
                $table->id();
                $table->uuid('session_id')->index();
                $table->char('token_hash', 64)->unique();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    /**
     * Drop both tables (every v2 session can no longer refresh; live access tokens keep
     * working until they expire).
     *
     * @param Builder $schema
     * @return void
     */
    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(GP247_DB_PREFIX . 'api_refresh_token');
        $schema->dropIfExists(GP247_DB_PREFIX . 'api_session');
    }
}
