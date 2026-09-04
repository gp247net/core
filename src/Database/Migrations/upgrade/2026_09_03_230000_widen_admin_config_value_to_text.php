<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent upgrade: widen admin_config.value varchar(500) -> TEXT on installed sites.
 *
 * WHY: at-rest encryption (ADR compat-foundation_config-secret-at-rest) wraps a secret
 * as "enc:v1:" + AES ciphertext + HMAC (base64), which is several times longer than the
 * plaintext; a long token would overflow varchar(500) and be truncated (RISK-TECH-secret-
 * column-overflow). This MUST run before the encrypt-known-secrets migration.
 *
 * Runs via gp247:core-update (--path upgrade/), never the create-tables migration (which
 * already ships TEXT on a fresh install). No-op when the column is already TEXT.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-config-secret-at-rest
 * @aidlc-adr compat-foundation_config-secret-at-rest
 */
return new class extends Migration
{
    /**
     * Widen the column to TEXT unless it already is.
     *
     * @return void
     */
    public function up()
    {
        $table = GP247_DB_PREFIX . 'admin_config';

        if ($this->currentType($table) === 'text') {
            return;
        }

        Schema::connection(GP247_DB_CONNECTION)->table($table, function (Blueprint $blueprint) {
            $blueprint->text('value')->nullable()->change();
        });
    }

    /**
     * Read the current data type of admin_config.value via information_schema (no dbal).
     *
     * @param string $fullTable Fully prefixed table name.
     * @return string|null Lower-case DATA_TYPE, or null when unknown.
     */
    private function currentType(string $fullTable): ?string
    {
        try {
            $connection = DB::connection(GP247_DB_CONNECTION);
            $row = $connection->selectOne(
                'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$connection->getDatabaseName(), $fullTable, 'value']
            );
        } catch (\Throwable $e) {
            return null;
        }

        return isset($row->type) ? strtolower((string) $row->type) : null;
    }

    /**
     * No structural rollback: narrowing back to varchar(500) could truncate an encrypted
     * value and lose the secret. Left intentionally empty (data-preserving).
     *
     * @return void
     */
    public function down()
    {
        // Intentionally empty — see method doc.
    }
};
