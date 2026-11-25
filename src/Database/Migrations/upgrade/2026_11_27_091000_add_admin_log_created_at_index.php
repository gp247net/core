<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on `admin_log.created_at`: the retention of the administrators' log deletes its oldest
 * rows a slice at a time, which without it reads the whole table on every slice.
 *
 * Idempotent and add-only: guarded by hasIndex; no row is read, changed or removed. A fresh
 * install already has the index from the create-tables migration. On a very large log table run
 * it out of peak hours.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-admin-log
 * @aidlc-adr personal-data-retention_sweep-runner
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'admin_log';
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'created_at') && !$schema->hasIndex($table, ['created_at'])) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->index('created_at'));
        }
    }

    /**
     * Drops only the index (no data is lost).
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'admin_log';
        if ($schema->hasTable($table) && $schema->hasIndex($table, ['created_at'])) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex(['created_at']));
        }
    }
};
