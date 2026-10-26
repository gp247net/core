<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent upgrade (expand step): API Connection keys are matched by a SHA-256
 * hash instead of the stored plain key.
 *
 * Adds `apikey_hash` and fills it for rows that still only have the plain key, so
 * every key in use keeps working without being reissued. The plain `apikey` column
 * is left untouched here; new keys are no longer written to it, and a later release
 * clears it (contract step) once every site has run this migration.
 * Backup: none needed — nothing is overwritten or removed.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-connection-key-hashing
 * @aidlc-adr compat-foundation_rest-api-hardening
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
        if (!$schema->hasTable($table)) {
            return;
        }
        if (!$schema->hasColumn($table, 'apikey_hash')) {
            $schema->table($table, function (Blueprint $blueprint) {
                $blueprint->char('apikey_hash', 64)->nullable()->index()->after('apikey');
            });
        }

        // WHY PHP hashing in batches: hash functions differ between MySQL, MariaDB and
        // SQLite, and the table is small but unbounded on a public install.
        DB::connection(GP247_DB_CONNECTION)->table($table)
            ->whereNull('apikey_hash')
            ->where('apikey', '<>', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::connection(GP247_DB_CONNECTION)->table($table)
                        ->where('id', $row->id)
                        ->whereNull('apikey_hash')
                        ->update(['apikey_hash' => hash('sha256', (string) $row->apikey)]);
                }
            });
    }

    /**
     * WARNING: connections created after this upgrade exist only as a hash; dropping
     * the column makes their keys unusable (they must be reissued). Rows created
     * before still have their plain key.
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'api_connection';
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'apikey_hash')) {
            $schema->table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex([ 'apikey_hash' ]);
                $blueprint->dropColumn('apikey_hash');
            });
        }
    }
};
