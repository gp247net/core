<?php

use GP247\Core\Models\AdminApiConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract step of the API Connection key hashing (expand: 2026_10_26_191822): every
 * key still held in the plain `apikey` column gets its SHA-256 in `apikey_hash` first,
 * then the plain column is dropped, so a database copy no longer holds a usable key.
 * Keys in use keep working through the hash. Idempotent: nothing to do once the
 * column is gone (fresh installs never create it).
 *
 * Data loss (approved 2026-10-27, mod 20261027T154124): the plain keys themselves.
 * Back up the database before `gp247:update` if you need them.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-connection-key-hashing
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'api_connection';
        if (!$schema->hasTable($table) || !$schema->hasColumn($table, 'apikey')) {
            return;
        }

        // WHY: a site jumping from before 3.2.3 still lacks the hash column if the expand
        // migration was skipped; the hash must exist before the plain keys can go.
        if (!$schema->hasColumn($table, 'apikey_hash')) {
            $schema->table($table, function (Blueprint $blueprint) {
                $blueprint->char('apikey_hash', 64)->nullable()->index()->after('apikey');
            });
        }

        DB::connection(GP247_DB_CONNECTION)->table($table)
            ->whereNull('apikey_hash')
            ->where('apikey', '<>', '')
            ->select(['id', 'apikey'])
            ->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::connection(GP247_DB_CONNECTION)->table($table)
                        ->where('id', $row->id)
                        ->whereNull('apikey_hash')
                        ->update(['apikey_hash' => hash('sha256', (string) $row->apikey)]);
                }
            });

        $schema->table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('apikey');
        });

        if (class_exists(AdminApiConnection::class)) {
            AdminApiConnection::forgetSchemaMemo();
        }
    }

    /**
     * WARNING: irreversible for data — the plain keys are gone. This only puts back an
     * empty, nullable `apikey` column so older code that expects it does not fail;
     * every key keeps working through `apikey_hash`.
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'api_connection';
        if ($schema->hasTable($table) && !$schema->hasColumn($table, 'apikey')) {
            $schema->table($table, function (Blueprint $blueprint) {
                $blueprint->string('apikey', 128)->nullable()->after('apiconnection');
            });
        }

        if (class_exists(AdminApiConnection::class)) {
            AdminApiConnection::forgetSchemaMemo();
        }
    }
};
