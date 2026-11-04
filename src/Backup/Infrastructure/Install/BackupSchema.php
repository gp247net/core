<?php

namespace GP247\Core\Backup\Infrastructure\Install;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Tables of the backup area, shared by the fresh-install migration and the upgrade migration
 * so both build the same schema. Creating is idempotent; dropping keeps any table that still
 * holds data (archives or tokens are never removed by a rollback).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-install-lifecycle
 * @aidlc-adr backup-engine_core-module-layout
 */
final class BackupSchema
{
    /** @var list<string> Unprefixed table names. */
    public const TABLES = ['backup_archive', 'backup_job', 'backup_download_token'];

    /**
     * Create the backup tables that are missing.
     *
     * @param Builder $schema
     * @return void
     */
    public static function create(Builder $schema): void
    {
        $p = GP247_DB_PREFIX;
        if (!$schema->hasTable($p . 'backup_archive')) {
            $schema->create($p . 'backup_archive', function (Blueprint $table) {
                $table->string('id', 26)->primary();
                $table->string('kind', 20);
                $table->string('reason', 100);
                $table->text('scope')->nullable();
                $table->string('status', 20)->index();
                $table->string('consistency', 20)->nullable();
                $table->string('origin', 20)->default('site');
                $table->string('offsite', 20)->default('none');
                $table->boolean('pinned')->default(false);
                $table->string('key_id', 32);
                $table->unsignedSmallInteger('format')->default(1);
                $table->unsignedBigInteger('size')->default(0);
                $table->unsignedInteger('parts')->default(0);
                $table->string('error', 255)->nullable();
                $table->string('created_by', 100)->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->index(['kind', 'status']);
            });
        }
        if (!$schema->hasTable($p . 'backup_job')) {
            $schema->create($p . 'backup_job', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20);
                $table->string('archive_id', 26)->nullable()->index();
                $table->string('status', 20)->index();
                // Encrypted with gp247_secret_encrypt() — may hold the data key of a running archive.
                $table->longText('cursor')->nullable();
                $table->string('lease_owner', 64)->nullable();
                $table->timestamp('lease_until')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->string('error', 255)->nullable();
                $table->timestamps();
            });
        }
        if (!$schema->hasTable($p . 'backup_download_token')) {
            $schema->create($p . 'backup_download_token', function (Blueprint $table) {
                $table->id();
                $table->char('token_hash', 64)->unique();
                $table->string('archive_id', 26)->index();
                $table->string('admin_id', 100);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    /**
     * Drop every backup table (fresh-install migration rollback = full uninstall).
     *
     * @param Builder $schema
     * @return void
     */
    public static function drop(Builder $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->dropIfExists(GP247_DB_PREFIX . $table);
        }
    }

    /**
     * Drop only the backup tables that are empty (upgrade migration rollback).
     *
     * @param Builder             $schema
     * @param ConnectionInterface $db
     * @return list<string> Tables kept because they hold rows.
     */
    public static function dropIfEmpty(Builder $schema, ConnectionInterface $db): array
    {
        $kept = [];
        foreach (self::TABLES as $table) {
            if (!$schema->hasTable(GP247_DB_PREFIX . $table)) {
                continue;
            }
            if ($db->table(GP247_DB_PREFIX . $table)->exists()) {
                $kept[] = $table;
                continue;
            }
            $schema->drop(GP247_DB_PREFIX . $table);
        }

        return $kept;
    }
}
