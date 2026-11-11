<?php

namespace GP247\Core\Backup\Infrastructure\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * Reads a table page by page in a stable order with constant memory. The position between
 * pages is a value, not a database cursor, so reading can stop at the end of one request
 * and go on in the next:
 *  - "key"  : keyset on the primary key (or a unique index whose columns are all NOT NULL),
 *             composite keys included — `WHERE a > ? OR (a = ? AND b > ?) ORDER BY a, b`;
 *  - "scan" : no usable key — LIMIT / OFFSET pages; offsets are stable inside one snapshot only,
 *             so the caller records a warning when such a table spans several requests.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class TableReader
{
    public const MODE_KEY = 'key';
    public const MODE_SCAN = 'scan';

    /**
     * @param ConnectionInterface $db Connection the rows are read from.
     */
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * @param string $table Real table name.
     * @return array{columns: list<string>, key: list<string>, mode: string, row_bytes: int|null}
     *         row_bytes: average row size the database reports (MySQL / MariaDB), null when unknown.
     * @throws TableMissing When the table does not exist (schema listings return [] instead of failing).
     */
    public function describe(string $table): array
    {
        $schema = $this->db->getSchemaBuilder();
        if (!$schema->hasTable($table)) {
            throw new TableMissing($table);
        }
        $columns = [];
        $nullable = [];
        foreach ($schema->getColumns($table) as $column) {
            $nullable[(string) $column['name']] = (bool) ($column['nullable'] ?? true);
            // Generated columns are computed by the database and can never be inserted back.
            if (empty($column['generation'])) {
                $columns[] = (string) $column['name'];
            }
        }
        if ($columns === []) {
            throw new TableMissing($table);
        }

        $key = [];
        $unique = null;
        foreach ($schema->getIndexes($table) as $index) {
            $cols = array_values(array_map('strval', (array) ($index['columns'] ?? [])));
            if ($cols === []) {
                continue;
            }
            if (!empty($index['primary'])) {
                $key = $cols;
                break;
            }
            $notNull = array_filter($cols, fn ($c) => isset($nullable[$c]) && !$nullable[$c]) === $cols;
            if ($unique === null && !empty($index['unique']) && $notNull) {
                $unique = $cols;
            }
        }
        $key = $key ?: ($unique ?? []);

        return ['columns' => $columns, 'key' => $key, 'mode' => $key === [] ? self::MODE_SCAN : self::MODE_KEY, 'row_bytes' => $this->rowBytes($table)];
    }

    /**
     * @param string $table Real table name.
     * @return int|null Average row size from the table statistics, refreshed on MySQL 8 (which
     *                  otherwise caches them up to a day); null when the driver does not tell.
     *
     * @aidlc-story US-backup-engine-resumable-run
     */
    private function rowBytes(string $table): ?int
    {
        if (!in_array($this->db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }
        try {
            SchemaStats::fresh($this->db);
            $row = $this->db->selectOne('SELECT AVG_ROW_LENGTH AS l FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        } catch (\Throwable $e) {
            return null;
        }
        $bytes = (int) ($row->l ?? 0);

        return $bytes > 0 ? $bytes : null;
    }

    /**
     * One page of rows after a position.
     *
     * @param string                $table Real table name.
     * @param array                 $plan  From describe().
     * @param array<int,mixed>|null $after Key values of the last row read ("key"), null = start.
     * @param int                   $offset Rows already read ("scan").
     * @param int                   $limit Page size.
     * @return array{rows: list<list<mixed>>, after: array<int,mixed>|null, offset: int, done: bool}
     *         Rows as value lists in column order.
     * @throws TableMissing When the table was dropped meanwhile.
     */
    public function page(string $table, array $plan, ?array $after, int $offset, int $limit): array
    {
        $limit = max(1, $limit);
        $query = $this->db->table($table)->select($plan['columns']);
        if ($plan['mode'] === self::MODE_KEY) {
            if ($after !== null) {
                $this->afterKey($query, $plan['key'], array_values($after));
            }
            foreach ($plan['key'] as $column) {
                $query->orderBy($column);
            }
        } else {
            $query->offset(max(0, $offset));
        }

        try {
            $records = $query->limit($limit)->get();
        } catch (QueryException $e) {
            if (!$this->db->getSchemaBuilder()->hasTable($table)) {
                throw new TableMissing($table);
            }
            throw $e;
        }
        $rows = [];
        foreach ($records as $record) {
            $record = (array) $record;
            $values = [];
            foreach ($plan['columns'] as $column) {
                $values[] = $record[$column] ?? null;
            }
            $rows[] = $values;
        }

        $last = $rows === [] ? null : (array) $rows[count($rows) - 1];
        $nextAfter = $after;
        if ($plan['mode'] === self::MODE_KEY && $last !== null) {
            $positions = array_flip($plan['columns']);
            $nextAfter = array_map(fn ($c) => $last[$positions[$c]], $plan['key']);
        }

        return [
            'rows' => $rows,
            'after' => $nextAfter,
            'offset' => $offset + count($rows),
            'done' => count($rows) < $limit,
        ];
    }

    /**
     * Rows strictly after a composite key: (k1 > v1) OR (k1 = v1 AND k2 > v2) OR …
     *
     * @param Builder          $query
     * @param list<string>     $key
     * @param list<mixed>      $values
     * @return void
     */
    private function afterKey(Builder $query, array $key, array $values): void
    {
        $query->where(function (Builder $outer) use ($key, $values) {
            foreach ($key as $i => $column) {
                $outer->orWhere(function (Builder $inner) use ($key, $values, $i, $column) {
                    for ($j = 0; $j < $i; $j++) {
                        $inner->where($key[$j], '=', $values[$j] ?? null);
                    }
                    $inner->where($column, '>', $values[$i] ?? null);
                });
            }
        });
    }
}
