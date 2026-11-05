<?php

namespace GP247\Core\Support\LoginFailure;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Table of failed sign-ins, shared by the fresh-install migration and the upgrade
 * migration so both build the same schema. Creating is idempotent.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-log
 * @aidlc-adr admin-shell_login-failure-log
 */
final class LoginFailureSchema
{
    public const TABLE = 'admin_login_failure';

    /**
     * Create the table when it is missing.
     *
     * @param Builder $schema
     * @return void
     */
    public static function create(Builder $schema): void
    {
        if ($schema->hasTable(GP247_DB_PREFIX . self::TABLE)) {
            return;
        }
        $schema->create(GP247_DB_PREFIX . self::TABLE, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('audience', 30);
            $table->string('channel', 10);
            $table->string('identity', 191)->nullable();
            $table->string('account_id', 64)->nullable();
            $table->string('reason', 20);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['audience', 'created_at']);
            $table->index(['ip', 'created_at']);
        });
    }

    /**
     * WARNING: drops the table — every recorded failed sign-in is lost.
     *
     * @param Builder $schema
     * @return void
     */
    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(GP247_DB_PREFIX . self::TABLE);
    }
}
