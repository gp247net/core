<?php

namespace GP247\Core\Backup\Infrastructure\Database;

use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Format\RowCodec;
use Illuminate\Database\ConnectionInterface;

/**
 * What a restore needs to know about the site schema, always limited to the connection's own
 * database (since Laravel 12 a listing without a schema covers every database the account sees —
 * a shared MySQL server would expose other sites' tables).
 *
 * Supported drivers: MySQL / MariaDB (production) and SQLite. Identifiers handed to raw
 * statements are checked against the safe-name pattern and quoted by {@see quote()}.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-full-database, US-backup-restore-version-compatibility
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class SchemaInspector
{
    /** @var array<string, array{name: string, size: int}>|null */
    private ?array $tables = null;

    /**
     * @param ConnectionInterface $db
     * @throws RestoreRefused When the driver or a connection-level table prefix is not supported.
     */
    public function __construct(private readonly ConnectionInterface $db)
    {
        if (!in_array($db->getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RestoreRefused(RestoreRefused::DRIVER_UNSUPPORTED, $db->getDriverName());
        }
        // WHY: GP247 adds its own prefix to names; a second, connection-level prefix would make
        // the raw DDL below address other tables than the query builder does.
        if ($db->getTablePrefix() !== '') {
            throw new RestoreRefused(RestoreRefused::DRIVER_UNSUPPORTED, 'connection table prefix');
        }
    }

    /**
     * @return ConnectionInterface
     */
    public function db(): ConnectionInterface
    {
        return $this->db;
    }

    /**
     * DDL commits any open transaction implicitly: a caller's transaction would end silently
     * and its later writes would no longer roll back — so restore DDL never runs inside one.
     *
     * @return void
     * @throws RestoreRefused
     */
    public function assertNoTransaction(): void
    {
        if ($this->db->transactionLevel() > 0) {
            throw new RestoreRefused(RestoreRefused::IN_TRANSACTION);
        }
    }

    /**
     * @return bool MySQL / MariaDB.
     */
    public function isMysql(): bool
    {
        return $this->db->getDriverName() !== 'sqlite';
    }

    /**
     * @return array<string, array{name: string, size: int}> Base tables of the current database by real name.
     */
    public function tables(): array
    {
        if ($this->tables === null) {
            $schema = $this->db->getSchemaBuilder();
            $this->tables = [];
            foreach ($schema->getTables($schema->getCurrentSchemaListing()) as $table) {
                $name = (string) ($table['name'] ?? '');
                if ($name !== '') {
                    $this->tables[$name] = ['name' => $name, 'size' => (int) ($table['size'] ?? 0)];
                }
            }
        }

        return $this->tables;
    }

    /**
     * @return void Forget the cached table list (after DDL).
     */
    public function refresh(): void
    {
        $this->tables = null;
    }

    /**
     * @param string $table Real name.
     * @return bool
     */
    public function hasTable(string $table): bool
    {
        return isset($this->tables()[$table]);
    }

    /**
     * @param string $table Real name.
     * @return list<string> Insertable columns (generated columns left out), in table order.
     */
    public function columns(string $table): array
    {
        return (new TableReader($this->db))->describe($table)['columns'];
    }

    /**
     * @param string $table Real name.
     * @return array{columns: list<string>, key: list<string>, mode: string} See TableReader::describe().
     */
    public function describe(string $table): array
    {
        return (new TableReader($this->db))->describe($table);
    }

    /**
     * Foreign keys of tables OUTSIDE a set that point INTO it: renaming the set would leave them
     * pointing at the old tables (ADR shadow-table-swap §4).
     *
     * @param list<string> $tables Real names of the set.
     * @return list<array{table: string, references: string}>
     */
    public function incomingForeignKeys(array $tables): array
    {
        $set = array_flip($tables);
        $out = [];
        foreach ($this->foreignKeys() as $fk) {
            if (isset($set[$fk['references']]) && !isset($set[$fk['table']])) {
                $out[] = $fk;
            }
        }

        return $out;
    }

    /**
     * @param string $table Real name.
     * @return bool The table declares foreign keys of its own.
     */
    public function hasOutgoingForeignKeys(string $table): bool
    {
        foreach ($this->foreignKeys() as $fk) {
            if ($fk['table'] === $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $tables Real names.
     * @return int Bytes on disk (0 when the driver does not tell).
     */
    public function bytes(array $tables): int
    {
        $all = $this->tables();
        $sum = 0;
        foreach ($tables as $table) {
            $sum += $all[$table]['size'] ?? 0;
        }

        return $sum;
    }

    /**
     * @param string       $table   Real name.
     * @param list<string> $columns Columns of the table.
     * @param string       $after   Timestamp: rows created or updated after it are counted.
     * @return int
     */
    public function rowsChangedAfter(string $table, array $columns, string $after): int
    {
        $stamp = array_values(array_intersect(['created_at', 'updated_at'], $columns));
        if ($stamp === []) {
            return 0;
        }
        $query = $this->db->table($table);
        $query->where(function ($q) use ($stamp, $after) {
            foreach ($stamp as $column) {
                $q->orWhere($column, '>', $after);
            }
        });

        return (int) $query->count();
    }

    /**
     * @param string $table Real name of the migration table.
     * @return list<string> Migration names, empty when the table is missing.
     */
    public function migrations(string $table): array
    {
        if (!$this->hasTable($table)) {
            return [];
        }

        return $this->db->table($table)->orderBy('id')->pluck('migration')->map(fn ($m) => (string) $m)->all();
    }

    /**
     * Run statements with zero dates allowed in this session (MySQL / MariaDB): a table an older
     * site created with `DEFAULT '0000-00-00'` cannot even be copied with CREATE TABLE … LIKE under
     * the strict default (error 1067), nor its zero dates inserted back. Only NO_ZERO_DATE and
     * NO_ZERO_IN_DATE are lifted — strict mode stays, so a value that does not fit still fails
     * instead of being cut silently. The previous sql_mode is put back afterwards.
     *
     * @param callable $statements
     * @return mixed What $statements returns.
     *
     * @aidlc-story US-backup-restore-full-database
     */
    public function withZeroDatesAllowed(callable $statements): mixed
    {
        if (!$this->isMysql()) {
            return $statements();
        }
        $mode = (string) ($this->db->selectOne('SELECT @@SESSION.sql_mode AS m')->m ?? '');
        $loose = implode(',', array_filter(explode(',', $mode), fn ($m) => !in_array(strtoupper(trim($m)), ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE'], true)));
        if ($loose === $mode) {
            return $statements();
        }
        $this->db->statement('SET SESSION sql_mode = ?', [$loose]);
        try {
            return $statements();
        } finally {
            $this->db->statement('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    /**
     * @param string $table Real name.
     * @return int|null Next AUTO_INCREMENT value of the table (MySQL / MariaDB), null when it has none.
     *                  Read from SHOW CREATE TABLE: information_schema may hold a cached, older value.
     *
     * @aidlc-story US-backup-restore-full-database
     */
    public function autoIncrement(string $table): ?int
    {
        if (!$this->isMysql()) {
            return null;
        }
        $row = (array) $this->db->selectOne('SHOW CREATE TABLE ' . $this->quote($table));
        $ddl = (string) ($row['Create Table'] ?? '');

        return preg_match('/\bAUTO_INCREMENT=(\d+)/', $ddl, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Give a shadow at least the next id its table would have given: restored rows end where the
     * backup ended, and an id handed out (then deleted) before the backup must not come back.
     *
     * @param string $table  Real name of the table being replaced.
     * @param string $shadow Its loaded shadow.
     * @return void
     *
     * @aidlc-story US-backup-restore-full-database
     */
    public function carryAutoIncrement(string $table, string $shadow): void
    {
        $next = $this->autoIncrement($table);
        $current = $this->autoIncrement($shadow);
        if ($next === null || $current === null || $next <= $current) {
            return;
        }
        $this->withZeroDatesAllowed(fn () => $this->db->statement('ALTER TABLE ' . $this->quote($shadow) . ' AUTO_INCREMENT = ' . (int) $next));
    }

    /**
     * Triggers follow their table through RENAME: after a swap they sit on the old copy. Move the
     * site's own trigger definitions (never anything from the archive) to the table now in place.
     *
     * @param string $from Real name the triggers are on now.
     * @param string $to   Real name they belong to.
     * @return list<string> Names of triggers that could not be moved (left where they were).
     *
     * @aidlc-story US-backup-restore-full-database
     */
    public function moveTriggers(string $from, string $to): array
    {
        if (!$this->isMysql()) {
            return [];
        }
        $failed = [];
        foreach ($this->triggersOn($from) as $name) {
            try {
                $definition = (array) $this->db->selectOne('SHOW CREATE TRIGGER ' . $this->quote($name));
                // The stored statement names the table the trigger was created on (normally $to).
                $sql = preg_replace('/\bON\s+`?' . preg_quote($from, '/') . '`?(\s)/i', 'ON ' . $this->quote($to) . '$1', (string) ($definition['SQL Original Statement'] ?? ''), 1);
                $temporary = substr($name, 0, 55) . '_bkmv';
                $temporarySql = is_string($sql) ? preg_replace('/\bTRIGGER\s+`?' . preg_quote($name, '/') . '`?/i', 'TRIGGER ' . $this->quote($temporary), $sql, 1) : null;
                // WHY a temporary copy first: a CREATE refused after the DROP (definer rights, binlog
                // without SUPER) would lose the site's trigger. Nothing is dropped until the copy exists.
                if (!is_string($temporarySql) || $temporarySql === $sql || !$this->createTrigger($temporarySql)) {
                    $failed[] = $name;
                    continue;
                }
                $this->db->unprepared('DROP TRIGGER ' . $this->quote($name));
                if ($this->createTrigger($sql)) {
                    $this->db->unprepared('DROP TRIGGER ' . $this->quote($temporary));
                } else {
                    // The copy keeps the behaviour on the right table, under its temporary name.
                    $failed[] = $name;
                }
            } catch (\Throwable $e) {
                $failed[] = $name;
            }
        }

        return $failed;
    }

    /**
     * @param string $table Real table name.
     * @return list<string> Names of the triggers defined on it (MySQL / MariaDB; none elsewhere).
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-full-database
     */
    public function triggersOn(string $table): array
    {
        if (!$this->isMysql()) {
            return [];
        }

        return array_map(
            fn ($row) => (string) $row->n,
            $this->db->select('SELECT TRIGGER_NAME AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?', [$table])
        );
    }

    /**
     * @param string $sql CREATE TRIGGER statement (the site's own definition).
     * @return bool Created — as it was, or without its DEFINER clause when the definer cannot be set
     *              by this database user (the trigger then runs as this user, like the site's own code).
     */
    private function createTrigger(string $sql): bool
    {
        foreach ([$sql, preg_replace('/\bDEFINER\s*=\s*\S+\s+/i', '', $sql, 1)] as $statement) {
            if (!is_string($statement)) {
                continue;
            }
            try {
                $this->db->unprepared($statement);

                return true;
            } catch (\Throwable $e) {
                // Next form, or give up.
            }
        }

        return false;
    }

    /**
     * @param string $table Real name.
     * @return array<string, int> BIT columns => bytes of their value (MySQL / MariaDB).
     *
     * @aidlc-story US-backup-restore-full-database
     */
    public function bitColumns(string $table): array
    {
        if (!$this->isMysql()) {
            return [];
        }
        $out = [];
        foreach ($this->db->select("SELECT COLUMN_NAME AS c, NUMERIC_PRECISION AS p FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND DATA_TYPE = 'bit'", [$table]) as $row) {
            $out[(string) $row->c] = max(1, intdiv((int) $row->p + 7, 8));
        }

        return $out;
    }

    /**
     * @param string $name Real table (or index) name.
     * @return string Quoted identifier for raw statements.
     * @throws \InvalidArgumentException When the name is not a safe identifier.
     */
    public function quote(string $name): string
    {
        if (!RowCodec::validName($name)) {
            throw new \InvalidArgumentException('Unsafe identifier.');
        }

        return $this->isMysql() ? '`' . $name . '`' : '"' . $name . '"';
    }

    /**
     * @return list<array{table: string, references: string}> Every foreign key of the current database.
     */
    private function foreignKeys(): array
    {
        if ($this->isMysql()) {
            $rows = $this->db->select(
                'SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
            );

            return array_map(fn ($r) => ['table' => (string) $r->t, 'references' => (string) $r->r], $rows);
        }
        $out = [];
        foreach (array_keys($this->tables()) as $table) {
            foreach ($this->db->getSchemaBuilder()->getForeignKeys($table) as $fk) {
                $out[] = ['table' => $table, 'references' => (string) $fk['foreign_table']];
            }
        }

        return $out;
    }
}
