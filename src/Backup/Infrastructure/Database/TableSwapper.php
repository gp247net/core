<?php

namespace GP247\Core\Backup\Infrastructure\Database;

use GP247\Core\Backup\Domain\RestoreRefused;
use RuntimeException;

/**
 * Swaps a set of tables with their shadow copies in one atomic step (ADR shadow-table-swap §2):
 * MySQL / MariaDB one `RENAME TABLE T TO T__bko, T__bkr TO T, …` statement; SQLite renames in
 * one transaction. The old tables stay as T__bko — the safety snapshot behind "undo" — until
 * they expire. Names longer than the 64-character limit get a short hash.
 *
 * DDL commits any open transaction implicitly, so nothing here runs inside one: a caller's
 * transaction would end silently and its later writes would not roll back.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-config-restore, US-backup-restore-full-database
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class TableSwapper
{
    public const SHADOW = 'bkr';
    public const OLD = 'bko';

    private const MAX_NAME = 64;
    /** Seconds a rename waits for other sessions' metadata locks before failing (MySQL default: one year). */
    private const LOCK_WAIT_SECONDS = 15;

    /**
     * @param SchemaInspector $schema
     */
    public function __construct(private readonly SchemaInspector $schema)
    {
    }

    /**
     * @param string $table  Real name.
     * @param string $suffix self::SHADOW or self::OLD.
     * @return string Name of its shadow / old copy.
     */
    public static function name(string $table, string $suffix): string
    {
        $name = $table . '__' . $suffix;
        if (strlen($name) <= self::MAX_NAME) {
            return $name;
        }

        return substr($table, 0, self::MAX_NAME - 15) . '_' . substr(sha1($table), 0, 8) . '__' . $suffix;
    }

    /**
     * @param string $name Real table name.
     * @return bool A shadow or old copy made by a restore.
     */
    public static function isCopy(string $name): bool
    {
        return str_ends_with($name, '__' . self::SHADOW) || str_ends_with($name, '__' . self::OLD);
    }

    /**
     * Put the loaded shadows in place: T → T__bko, T__bkr → T for every table at once. An old
     * copy left by an earlier restore of the same table is dropped first (one undo point).
     *
     * @param list<string> $tables Real names; every T__bkr must exist.
     * @return list<string> Tables whose earlier undo copy was dropped.
     * @throws RestoreRefused When called inside a transaction.
     * @throws RuntimeException When a shadow is missing.
     */
    public function swap(array $tables): array
    {
        $this->assertNoTransaction();
        $dropped = [];
        foreach ($tables as $table) {
            if (!$this->exists(self::name($table, self::SHADOW))) {
                throw new RuntimeException('Shadow table missing.');
            }
            if ($this->exists(self::name($table, self::OLD))) {
                if (!$this->drop(self::name($table, self::OLD))) {
                    throw new RuntimeException('An earlier copy of ' . $table . ' still holds a trigger of the site.');
                }
                $dropped[] = $table;
            }
        }
        $pairs = [];
        foreach ($tables as $table) {
            $pairs[] = [$table, self::name($table, self::OLD)];
            $pairs[] = [self::name($table, self::SHADOW), $table];
        }
        $this->rename($pairs);

        return $dropped;
    }

    /**
     * Undo a swap: T → T__bkr (the restored data), T__bko → T (the data before the restore).
     *
     * @param list<string> $tables Real names; every T__bko must exist.
     * @return void
     * @throws RuntimeException When an old copy is missing.
     */
    public function revert(array $tables): void
    {
        $this->assertNoTransaction();
        $pairs = [];
        foreach ($tables as $table) {
            if (!$this->exists(self::name($table, self::OLD))) {
                throw new RuntimeException('Old copy missing.');
            }
            if ($this->exists(self::name($table, self::SHADOW)) && !$this->drop(self::name($table, self::SHADOW))) {
                throw new RuntimeException('A copy of ' . $table . ' still holds a trigger of the site.');
            }
            $pairs[] = [$table, self::name($table, self::SHADOW)];
            $pairs[] = [self::name($table, self::OLD), $table];
        }
        $this->rename($pairs);
    }

    /**
     * Where a set stands, for a slice that resumes after a request died around the swap.
     *
     * @param list<string> $tables
     * @return string "loaded" (every shadow present), "swapped" (no shadow, every old copy present) or "mixed".
     */
    public function state(array $tables): string
    {
        $shadows = 0;
        $olds = 0;
        foreach ($tables as $table) {
            $shadows += $this->exists(self::name($table, self::SHADOW)) ? 1 : 0;
            $olds += $this->exists(self::name($table, self::OLD)) ? 1 : 0;
        }

        return match (true) {
            $shadows === count($tables) => 'loaded',
            $shadows === 0 && $olds === count($tables) => 'swapped',
            default => 'mixed',
        };
    }

    /**
     * Never a copy that still holds triggers: a trigger that could not be moved to the table in
     * place stays on its copy, and dropping the copy would lose the site's trigger with it. Such a
     * copy is kept for a person to look at, whoever asks for the drop (clean-up, undo, next restore).
     *
     * @param string $name Real table name.
     * @return bool Dropped (or absent); false when kept because it is a copy holding a trigger.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-full-database
     */
    public function drop(string $name): bool
    {
        $this->assertNoTransaction();
        if (self::isCopy($name) && $this->schema->triggersOn($name) !== []) {
            return false;
        }
        $this->schema->db()->statement('DROP TABLE IF EXISTS ' . $this->schema->quote($name));
        $this->schema->refresh();

        return true;
    }

    /**
     * @param string $name Real table name.
     * @return bool
     */
    public function exists(string $name): bool
    {
        return $this->schema->hasTable($name);
    }

    /**
     * Shadow / old copies of THIS site's tables in the current database. Several sites (or
     * another application) may share one database with other prefixes: only copies of tables
     * carrying GP247_DB_PREFIX, or of this site's known unprefixed tables, are listed.
     *
     * @param list<string> $rawTables Real names of this site's unprefixed tables (migrations, declared tables).
     * @return list<string>
     */
    public function copies(array $rawTables = []): array
    {
        $own = [];
        foreach ($rawTables as $table) {
            $own[self::name($table, self::SHADOW)] = true;
            $own[self::name($table, self::OLD)] = true;
        }
        $prefix = (string) GP247_DB_PREFIX;

        return array_values(array_filter(
            array_keys($this->schema->tables()),
            fn ($name) => self::isCopy($name) && ($prefix === '' || str_starts_with($name, $prefix) || isset($own[$name]))
        ));
    }

    /**
     * @return void
     * @throws RestoreRefused
     */
    public function assertNoTransaction(): void
    {
        $this->schema->assertNoTransaction();
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs from => to, applied in order.
     * @return void
     */
    private function rename(array $pairs): void
    {
        $db = $this->schema->db();
        try {
            if ($this->schema->isMysql()) {
                // WHY: a long transaction elsewhere holds a metadata lock; waiting for it (default
                // one year) would also queue every later query on these tables and hang the site.
                $previous = $db->selectOne('SELECT @@SESSION.lock_wait_timeout AS t')->t ?? null;
                $db->statement('SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS);
                try {
                    $db->statement('RENAME TABLE ' . implode(', ', array_map(
                        fn ($p) => $this->schema->quote($p[0]) . ' TO ' . $this->schema->quote($p[1]),
                        $pairs
                    )));
                } finally {
                    if (is_numeric($previous)) {
                        $db->statement('SET SESSION lock_wait_timeout = ' . (int) $previous);
                    }
                }

                return;
            }
            // WHY legacy_alter_table: since SQLite 3.26 a rename also rewrites the foreign keys of
            // OTHER tables to follow it — the shadow of a child table would end up pointing at the
            // parent's old copy.
            $db->statement('PRAGMA legacy_alter_table = ON');
            try {
                $db->transaction(function () use ($db, $pairs) {
                    foreach ($pairs as [$from, $to]) {
                        $db->statement('ALTER TABLE ' . $this->schema->quote($from) . ' RENAME TO ' . $this->schema->quote($to));
                    }
                });
            } finally {
                $db->statement('PRAGMA legacy_alter_table = OFF');
            }
        } finally {
            $this->schema->refresh();
        }
    }
}
