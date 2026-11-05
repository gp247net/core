<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Database\SchemaInspector;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use Illuminate\Database\QueryException;

/**
 * R0 "add back deleted records" (US-backup-restore-deleted-records): rows of one table that are
 * in the archive but no longer on the site — compared by primary key — are inserted again, with
 * the rows of the declared child tables that belong to them, in ONE transaction.
 *
 * Only INSERT: a row that exists is never changed, even when it was edited after the backup
 * (Q10 (c) deferred). A row hitting a unique index of the site is skipped and reported; the rest
 * still go in. Tables are never renamed here. The backup feature's own admin_config rows are
 * never added back, and "remember me" tokens are left empty.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-deleted-records
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class UndeleteService
{
    private const LOOKUP_BATCH = 500;
    private const SAMPLE_COLUMNS = ['name', 'title', 'code', 'sku', 'email', 'username', 'first_name', 'last_name', 'total', 'created_at'];
    private const REPORTED_SKIPS = 100;
    /** Session-restoring tokens are never brought back: "remember me" revoked after the backup stays revoked. */
    public const CLEARED_COLUMNS = ['remember_token'];

    /**
     * @param SchemaInspector $schema
     * @param int             $maxRows Most rows one operation may insert.
     * @param \Closure|null   $tick    Called while the archive is scanned (the job renews its lease).
     */
    public function __construct(
        private readonly SchemaInspector $schema,
        private readonly int $maxRows = 5000,
        private readonly ?\Closure $tick = null,
    ) {
    }

    /**
     * Rows of a table that the archive has and the site no longer has.
     *
     * @param ArchiveReader                      $reader
     * @param array{name: string, real: string}  $parent
     * @param int                                $sample Rows shown (key + a few identifying columns).
     * @return array{key: string, missing: int, sample: list<array<string, mixed>>}
     * @throws RestoreRefused (no_key) When the table has no single-column primary key.
     */
    public function candidates(ArchiveReader $reader, array $parent, int $sample = 50): array
    {
        $key = $this->keyOf($parent['real']);
        $shown = array_values(array_intersect(self::SAMPLE_COLUMNS, $reader->manifest()['tables'][$parent['name']]['columns']));
        $missing = 0;
        $rows = [];
        foreach ($this->missingRows($reader, $parent, $key, null) as $row) {
            if ($this->isProtected($parent, $row)) {
                continue;
            }
            $missing++;
            if (count($rows) < $sample) {
                $rows[] = array_intersect_key($row, array_flip([$key, ...$shown]));
            }
        }

        return ['key' => $key, 'missing' => $missing, 'sample' => $rows];
    }

    /**
     * Insert missing rows of the parent table (all, or the selected keys) and their child rows.
     *
     * @param ArchiveReader                                         $reader
     * @param array{name: string, real: string}                     $parent
     * @param list<array{name: string, real: string, column: string}> $children Child tables present in the archive and on the site.
     * @param list<int|string>|null                                 $keys     Selected parent keys, null = every missing row.
     * @return array{added: array<string, int>, skipped_count: int, skipped: list<array{table: string, key: string|null, reason: string}>}
     * @throws RestoreRefused When there is no usable key or more rows than allowed.
     */
    public function apply(ArchiveReader $reader, array $parent, array $children, ?array $keys): array
    {
        $key = $this->keyOf($parent['real']);
        $selected = $keys === null ? null : array_flip(array_map('strval', $keys));
        $result = ['added' => [], 'skipped_count' => 0, 'skipped' => []];
        $parents = [];
        // Counted while streaming: a large emptied table is refused before it fills the memory.
        foreach ($this->missingRows($reader, $parent, $key, $selected) as $row) {
            if ($this->isProtected($parent, $row)) {
                $this->skip($result, $parent['name'], null, 'protected');
                continue;
            }
            $parents[] = $row;
            $this->assertWithinLimit(count($parents));
        }
        $childRows = $this->childRows($reader, $children, array_flip(array_map(fn ($r) => (string) $r[$key], $parents)), count($parents));

        $this->schema->db()->transaction(function () use ($parents, $parent, $key, $children, $childRows, &$result) {
            $columns = $this->schema->columns($parent['real']);
            $inserted = [];
            foreach ($parents as $row) {
                $id = (string) $row[$key];
                if ($this->insertRow($parent['real'], $columns, $row)) {
                    $inserted[$id] = true;
                    $result['added'][$parent['name']] = ($result['added'][$parent['name']] ?? 0) + 1;
                } else {
                    // Only a plain id is reported: another unique key may be personal data (an e-mail).
                    $this->skip($result, $parent['name'], $key === 'id' ? $id : null, 'unique_conflict');
                }
            }
            foreach ($children as $child) {
                $this->insertChildren($child, $childRows[$child['name']] ?? [], $inserted, $result);
            }
        });

        return $result;
    }

    /**
     * @param array{name: string, real: string, column: string} $child
     * @param list<array<string, mixed>>                         $rows     Archive rows of the child for added-back parents.
     * @param array<string, true>                                $inserted Parent keys really inserted.
     * @param array                                              $result
     * @return void
     */
    private function insertChildren(array $child, array $rows, array $inserted, array &$result): void
    {
        $plan = $this->schema->describe($child['real']);
        $present = [];
        foreach ($rows as $row) {
            $parentKey = (string) $row[$child['column']];
            $label = $plan['key'] === ['id'] ? (string) ($row['id'] ?? '') : null;
            if (!isset($inserted[$parentKey])) {
                $this->skip($result, $child['name'], $label, 'parent_skipped');
                continue;
            }
            if ($plan['key'] !== []) {
                $exists = $this->schema->db()->table($child['real']);
                foreach ($plan['key'] as $column) {
                    $exists->where($column, $row[$column] ?? null);
                }
                if ($exists->exists()) {
                    $this->skip($result, $child['name'], $label, 'exists');
                    continue;
                }
            } else {
                // WHY: without a key a child row cannot be told apart — only a parent with no
                // child rows left on the site gets the archive's rows back (no duplicates).
                $present[$parentKey] ??= $this->schema->db()->table($child['real'])->where($child['column'], $row[$child['column']])->exists();
                if ($present[$parentKey]) {
                    $this->skip($result, $child['name'], null, 'children_present');
                    continue;
                }
            }
            if ($this->insertRow($child['real'], $plan['columns'], $row)) {
                $result['added'][$child['name']] = ($result['added'][$child['name']] ?? 0) + 1;
            } else {
                $this->skip($result, $child['name'], $label, 'unique_conflict');
            }
        }
    }

    /**
     * @param string                $real
     * @param list<string>          $columns Site columns.
     * @param array<string, mixed>  $row     Archive row.
     * @return bool False when a unique index of the site refused it.
     */
    private function insertRow(string $real, array $columns, array $row): bool
    {
        $values = array_intersect_key($row, array_flip($columns));
        foreach (self::CLEARED_COLUMNS as $column) {
            if (array_key_exists($column, $values)) {
                $values[$column] = null;
            }
        }
        $db = $this->schema->db();
        try {
            // A savepoint per row: a refused row does not roll back the others.
            $db->transaction(fn () => $db->table($real)->insert($values));

            return true;
        } catch (QueryException $e) {
            if (self::isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * @param array{name: string, real: string} $parent
     * @param array<string, mixed>              $row
     * @return bool A backup-feature row of admin_config, which is never taken from an archive.
     */
    private function isProtected(array $parent, array $row): bool
    {
        return ConfigRestoreRules::isConfigTable($parent['real']) && ConfigRestoreRules::isBackupRow($row);
    }

    /**
     * @param ArchiveReader                     $reader
     * @param array{name: string, real: string} $parent
     * @param string                            $key
     * @param array<string, int>|null           $selected Selected keys (as strings), null = all.
     * @return \Generator<int, array<string, mixed>>
     */
    private function missingRows(ArchiveReader $reader, array $parent, string $key, ?array $selected): \Generator
    {
        $batch = [];
        foreach ($reader->rows($parent['name']) as $row) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            if ($selected !== null && !isset($selected[(string) $row[$key]])) {
                continue;
            }
            $batch[] = $row;
            if (count($batch) >= self::LOOKUP_BATCH) {
                yield from $this->absentOf($parent['real'], $key, $batch);
                $batch = [];
                $this->tick();
            }
        }
        if ($batch !== []) {
            yield from $this->absentOf($parent['real'], $key, $batch);
        }
    }

    /**
     * @param string                     $real
     * @param string                     $key
     * @param list<array<string, mixed>> $batch
     * @return list<array<string, mixed>> Rows of the batch whose key is not on the site.
     */
    private function absentOf(string $real, string $key, array $batch): array
    {
        $present = array_flip(array_map('strval', $this->schema->db()->table($real)->whereIn($key, array_column($batch, $key))->pluck($key)->all()));

        return array_values(array_filter($batch, fn ($row) => !isset($present[(string) $row[$key]])));
    }

    /**
     * @param ArchiveReader                                           $reader
     * @param list<array{name: string, real: string, column: string}> $children
     * @param array<string, int>                                      $parentKeys
     * @param int                                                     $taken      Rows already collected (limit).
     * @return array<string, list<array<string, mixed>>> Child name => its archive rows for those parents.
     */
    private function childRows(ArchiveReader $reader, array $children, array $parentKeys, int $taken): array
    {
        $out = [];
        if ($parentKeys === []) {
            return $out;
        }
        foreach ($children as $child) {
            $seen = 0;
            foreach ($reader->rows($child['name']) as $row) {
                if (isset($row[$child['column']]) && isset($parentKeys[(string) $row[$child['column']]])) {
                    $out[$child['name']][] = $row;
                    $this->assertWithinLimit(++$taken);
                }
                if (++$seen % self::LOOKUP_BATCH === 0) {
                    $this->tick();
                }
            }
        }

        return $out;
    }

    /**
     * @param int $rows Rows collected so far.
     * @return void
     * @throws RestoreRefused
     */
    private function assertWithinLimit(int $rows): void
    {
        if ($rows > $this->maxRows) {
            throw new RestoreRefused(RestoreRefused::TOO_MANY_ROWS, 'more than ' . $this->maxRows . ' rows', ['limit' => $this->maxRows]);
        }
    }

    /**
     * @return void
     */
    private function tick(): void
    {
        if ($this->tick !== null) {
            ($this->tick)();
        }
    }

    /**
     * @param string $real
     * @return string Single-column primary (or unique NOT NULL) key.
     * @throws RestoreRefused
     */
    private function keyOf(string $real): string
    {
        $key = $this->schema->describe($real)['key'];
        if (count($key) !== 1) {
            throw new RestoreRefused(RestoreRefused::NO_KEY, $real);
        }

        return $key[0];
    }

    /**
     * @param array       $result
     * @param string      $table
     * @param string|null $key
     * @param string      $reason
     * @return void
     */
    private function skip(array &$result, string $table, ?string $key, string $reason): void
    {
        $result['skipped_count']++;
        if (count($result['skipped']) < self::REPORTED_SKIPS) {
            $result['skipped'][] = ['table' => $table, 'key' => $key, 'reason' => $reason];
        }
    }

    /**
     * @param QueryException $e
     * @return bool MySQL / MariaDB 1062, SQLite UNIQUE / PRIMARY KEY constraint.
     */
    private static function isUniqueViolation(QueryException $e): bool
    {
        $info = $e->errorInfo ?? [];
        if ((int) ($info[1] ?? 0) === 1062) {
            return true;
        }

        return (string) ($info[0] ?? '') === '23000' && preg_match('/UNIQUE constraint failed|PRIMARY KEY/i', (string) ($info[2] ?? $e->getMessage())) === 1;
    }
}
