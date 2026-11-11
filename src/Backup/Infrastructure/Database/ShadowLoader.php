<?php

namespace GP247\Core\Backup\Infrastructure\Database;

use GP247\Core\Backup\Domain\RestoreRefused;
use RuntimeException;

/**
 * Builds the shadow copy T__bkr of a site table and fills it with rows from an archive, while
 * the real table T is not touched (ADR shadow-table-swap §1).
 *
 * The shadow has the CURRENT structure of T (MySQL `CREATE TABLE … LIKE`; SQLite the stored
 * CREATE statement under the new name). Rows are inserted by column name, only the columns the
 * archive and the site have in common: a column the site added since the backup takes its
 * schema default, a column the site dropped is left out. Values are data handed to bound
 * parameters — nothing read from an archive is ever executed.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-full-database, US-backup-restore-version-compatibility
 * @aidlc-adr backup-restore_shadow-table-swap, backup-engine_tamper-evidence
 */
final class ShadowLoader
{
    /** Bytes of values per INSERT statement (well under MySQL's default max_allowed_packet). */
    private const BATCH_BYTES = 1_048_576;
    private const BATCH_ROWS = 500;
    /** SQLite builds before 3.32 allow 999 bound parameters per statement. */
    private const SQLITE_PARAMS = 999;

    /** @var array<string, array<string, int>> Shadow => its BIT columns (bytes). */
    private array $bits = [];

    /**
     * @param SchemaInspector $schema
     */
    public function __construct(private readonly SchemaInspector $schema)
    {
    }

    /**
     * Create an empty shadow of a table (an earlier leftover shadow is dropped first).
     *
     * @param string $table Real name.
     * @return string Shadow name.
     * @throws RestoreRefused When called inside a transaction.
     */
    public function create(string $table): string
    {
        $this->assertNoTransaction();
        $db = $this->schema->db();
        $shadow = TableSwapper::name($table, TableSwapper::SHADOW);
        if (!(new TableSwapper($this->schema))->drop($shadow)) {
            throw new RuntimeException('A copy of ' . $table . ' still holds a trigger of the site.');
        }

        if ($this->schema->isMysql()) {
            // Zero-date defaults of an older site's table are copied as they are (error 1067 otherwise).
            $this->schema->withZeroDatesAllowed(fn () => $db->statement('CREATE TABLE ' . $this->schema->quote($shadow) . ' LIKE ' . $this->schema->quote($table)));
        } else {
            $this->createSqlite($table, $shadow);
        }
        $this->schema->refresh();

        return $shadow;
    }

    /**
     * Insert rows into a shadow.
     *
     * @param string                       $shadow  Shadow name.
     * @param list<string>                 $columns Columns to insert (see commonColumns()).
     * @param iterable<array<string,mixed>> $rows    Rows keyed by archive column.
     * @return int Rows inserted.
     */
    public function insert(string $shadow, array $columns, iterable $rows): int
    {
        return $this->schema->withZeroDatesAllowed(fn () => $this->insertRows($shadow, $columns, $rows));
    }

    /**
     * @param string                        $shadow
     * @param list<string>                  $columns
     * @param iterable<array<string,mixed>> $rows
     * @return int
     */
    private function insertRows(string $shadow, array $columns, iterable $rows): int
    {
        if ($columns === []) {
            throw new RuntimeException('No column in common with the site table.');
        }
        $maxRows = $this->schema->isMysql() ? self::BATCH_ROWS : max(1, min(self::BATCH_ROWS, intdiv(self::SQLITE_PARAMS, count($columns))));
        $bits = $this->bits[$shadow] ??= $this->schema->bitColumns($shadow);

        $count = 0;
        $batch = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = self::bindable($row[$column] ?? null, $bits[$column] ?? null);
                $values[$column] = $value;
                $bytes += is_string($value) ? strlen($value) : 8;
            }
            $batch[] = $values;
            if (count($batch) >= $maxRows || $bytes >= self::BATCH_BYTES) {
                $count += $this->flush($shadow, $batch);
                $batch = [];
                $bytes = 0;
            }
        }

        return $count + $this->flush($shadow, $batch);
    }

    /**
     * The value as the database must receive it (mod 20261110T164834, measured):
     *  - a float bound by PDO is turned into text with `precision` (14 digits) — 0.30000000000000004
     *    came back as 0.3: send the shortest text that reads back as the same double;
     *  - a BIT value is read as a number (a decimal string beyond PHP_INT_MAX) but bound as text it
     *    is taken as bytes ("Data too long", the restore failed): send its big-endian bytes.
     *
     * @param mixed    $value
     * @param int|null $bitBytes Bytes of the BIT column, null for any other column.
     * @return mixed
     *
     * @aidlc-story US-backup-restore-full-database
     */
    public static function bindable(mixed $value, ?int $bitBytes = null): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($bitBytes !== null && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
            return self::bitBytes((string) $value, $bitBytes);
        }
        if (is_float($value) && is_finite($value)) {
            for ($digits = 15; $digits <= 17; $digits++) {
                $text = sprintf('%.' . $digits . 'G', $value);
                if ((float) $text === $value) {
                    return $text;
                }
            }
        }

        return $value;
    }

    /**
     * @param string $decimal Non-negative integer in decimal.
     * @param int    $bytes
     * @return string Big-endian bytes, left-padded to $bytes.
     */
    private static function bitBytes(string $decimal, int $bytes): string
    {
        $out = '';
        $digits = ltrim($decimal, '0');
        while ($digits !== '') {
            // Long division of the decimal string by 256.
            $rest = 0;
            $quotient = '';
            foreach (str_split($digits) as $digit) {
                $rest = $rest * 10 + (int) $digit;
                $quotient .= intdiv($rest, 256);
                $rest %= 256;
            }
            $out = chr($rest) . $out;
            $digits = ltrim($quotient, '0');
        }

        return str_pad(substr($out, -$bytes), $bytes, chr(0), STR_PAD_LEFT);
    }

    /**
     * @param list<string> $archiveColumns Columns of the table as written in the archive.
     * @param string       $table          Real name (or its shadow — same structure).
     * @return list<string> Columns the archive and the site have in common, in archive order.
     */
    public function commonColumns(array $archiveColumns, string $table): array
    {
        return array_values(array_intersect($archiveColumns, $this->schema->columns($table)));
    }

    /**
     * @param string $table Real name.
     * @return int
     */
    public function count(string $table): int
    {
        return (int) $this->schema->db()->table($table)->count();
    }

    /**
     * @param string                    $shadow
     * @param list<array<string,mixed>> $batch
     * @return int
     */
    private function flush(string $shadow, array $batch): int
    {
        if ($batch === []) {
            return 0;
        }
        $this->schema->db()->table($shadow)->insert($batch);

        return count($batch);
    }

    /**
     * SQLite has no CREATE TABLE … LIKE: replay the stored CREATE statement and its indexes under
     * new names. Index names are global in SQLite, so the shadow's indexes get a short suffix.
     *
     * @param string $table
     * @param string $shadow
     * @return void
     */
    private function createSqlite(string $table, string $shadow): void
    {
        $db = $this->schema->db();
        $sql = $db->selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])?->sql;
        if (!is_string($sql)) {
            throw new RuntimeException('Table definition not found.');
        }
        $created = preg_replace('/^\s*CREATE\s+TABLE\s+(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z0-9_]+)/i', 'CREATE TABLE ' . $this->schema->quote($shadow), $sql, 1, $replaced);
        if ($replaced !== 1) {
            throw new RuntimeException('Table definition not understood.');
        }
        $db->statement($created);

        $indexes = $db->select("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);
        foreach ($indexes as $index) {
            $base = preg_replace('/(__bk[0-9a-f]{6})+$/', '', (string) $index->name);
            $name = substr($base, 0, 50) . '__bk' . substr(sha1($shadow . '|' . $index->name), 0, 6);
            $statement = preg_replace(
                '/^\s*CREATE\s+(UNIQUE\s+)?INDEX\s+(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z0-9_]+)\s+ON\s+(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z0-9_]+)/i',
                'CREATE $1INDEX ' . $this->schema->quote($name) . ' ON ' . $this->schema->quote($shadow),
                (string) $index->sql,
                1,
                $done
            );
            if ($done !== 1) {
                throw new RuntimeException('Index definition not understood.');
            }
            $db->statement($statement);
        }
    }

    /**
     * @return void
     * @throws RestoreRefused
     */
    private function assertNoTransaction(): void
    {
        $this->schema->assertNoTransaction();
    }
}
