<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Database\SchemaInspector;
use GP247\Core\Backup\Infrastructure\Database\TableSwapper;
use Illuminate\Support\Carbon;

/**
 * What undoing the last restore would drop, before it runs: per table put back, the rows added,
 * changed and deleted since the restore; and the other tables with rows added or changed since then
 * that point at those tables (a declared relation, or a `<name>_id` column guessed from the table
 * name — GP247 has almost no real foreign keys, so a row is never claimed to point, only the table).
 *
 * Only counts: table and column names come from the undo point and the schema, never from input,
 * and values go through bindings. No row data leaves this class.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-undo-preview
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class UndoPreviewer
{
    /**
     * @param SchemaInspector         $schema
     * @param DataGroupRegistry|null  $registry
     */
    public function __construct(private SchemaInspector $schema, private ?DataGroupRegistry $registry = null)
    {
        $this->registry ??= new DataGroupRegistry;
    }

    /**
     * @param array<string, mixed> $point Undo point of the restore ledger (tables, swapped_at, row_counts, undo_until, archive_id).
     * @return array{tables: list<array{table: string, rows_now: int, rows_at_swap: int|null, added: int|null, changed: int|null, deleted: int|null, measured: bool}>,
     *               referencing: list<array{table: string, column: string, points_to: string, rows: int, certain: bool}>,
     *               swapped_at: string, undo_until: string, code: string}
     */
    public function preview(array $point): array
    {
        $tables = array_values(array_filter(array_map('strval', (array) ($point['tables'] ?? [])), fn ($t) => $t !== ''));
        $since = self::localTime((string) ($point['swapped_at'] ?? ''));
        $counts = array_map('intval', (array) ($point['row_counts'] ?? []));

        return [
            'tables' => array_map(fn (string $table) => $this->tableChanges($table, $since, $counts[$table] ?? null), $tables),
            'referencing' => $this->referencing($tables, $since),
            'swapped_at' => (string) ($point['swapped_at'] ?? ''),
            'undo_until' => (string) ($point['undo_until'] ?? ''),
            'code' => strtoupper(substr((string) ($point['archive_id'] ?? ''), -6)),
        ];
    }

    /**
     * @param string   $table      Real name of a table the undo puts back.
     * @param string   $since      Swap time in the application timezone.
     * @param int|null $rowsAtSwap Rows right after the swap (null: undo point made before this was recorded).
     * @return array{table: string, rows_now: int, rows_at_swap: int|null, added: int|null, changed: int|null, deleted: int|null, measured: bool}
     */
    private function tableChanges(string $table, string $since, ?int $rowsAtSwap): array
    {
        $columns = $this->schema->hasTable($table) ? $this->schema->columns($table) : [];
        $db = $this->schema->db();
        $rowsNow = $columns === [] ? 0 : (int) $db->table($table)->count();
        $created = in_array('created_at', $columns, true);
        $updated = in_array('updated_at', $columns, true);
        $added = $created && $since !== '' ? (int) $db->table($table)->where('created_at', '>', $since)->count() : null;
        $changed = null;
        if ($updated && $since !== '') {
            $query = $db->table($table)->where('updated_at', '>', $since);
            if ($created) {
                $query->where(fn ($q) => $q->whereNull('created_at')->orWhere('created_at', '<=', $since));
            }
            $changed = (int) $query->count();
        }
        $deleted = $rowsAtSwap !== null && $added !== null ? max(0, $rowsAtSwap + $added - $rowsNow) : null;

        return [
            'table' => $table,
            'rows_now' => $rowsNow,
            'rows_at_swap' => $rowsAtSwap,
            'added' => $added,
            'changed' => $changed,
            'deleted' => $deleted,
            'measured' => $added !== null && $changed !== null && $deleted !== null,
        ];
    }

    /**
     * Tables outside the undo with rows added or changed since the swap that point at one of the
     * tables put back.
     *
     * @param list<string> $tables Real names put back.
     * @param string       $since
     * @return list<array{table: string, column: string, points_to: string, rows: int, certain: bool}>
     */
    private function referencing(array $tables, string $since): array
    {
        if ($since === '' || $tables === []) {
            return [];
        }
        $targets = [];
        foreach ($tables as $table) {
            $bare = self::bare($table);
            $targets[$table] = ['declared' => DataGroupRegistry::relationsOf($bare), 'guessed' => self::guessedColumns($bare)];
        }
        $found = [];
        foreach (array_keys($this->schema->tables()) as $other) {
            if (in_array($other, $tables, true) || TableSwapper::isCopy($other) || (GP247_DB_PREFIX !== '' && !str_starts_with($other, GP247_DB_PREFIX))) {
                continue;
            }
            $columns = $this->schema->columns($other);
            $stamps = array_values(array_intersect(['created_at', 'updated_at'], $columns));
            if ($stamps === []) {
                continue;
            }
            foreach ($targets as $table => $target) {
                $declared = $target['declared'][self::bare($other)] ?? null;
                $column = $declared !== null && in_array($declared, $columns, true) ? $declared : (array_values(array_intersect($target['guessed'], $columns))[0] ?? null);
                if ($column === null) {
                    continue;
                }
                $rows = $this->rowsPointing($other, $column, $stamps, $since);
                if ($rows > 0) {
                    $found[] = ['table' => $other, 'column' => $column, 'points_to' => $table, 'rows' => $rows, 'certain' => $declared === $column];
                }
            }
        }

        return $found;
    }

    /**
     * @param string       $table
     * @param string       $column Column holding the key of the table put back.
     * @param list<string> $stamps created_at / updated_at present on the table.
     * @param string       $since
     * @return int Rows added or changed since the swap with that column set.
     */
    private function rowsPointing(string $table, string $column, array $stamps, string $since): int
    {
        return (int) $this->schema->db()->table($table)
            ->whereNotNull($column)
            ->where(function ($q) use ($stamps, $since) {
                foreach ($stamps as $stamp) {
                    $q->orWhere($stamp, '>', $since);
                }
            })
            ->count();
    }

    /**
     * `admin_user` ⇒ `admin_user_id`, `user_id`; `admin_role` ⇒ `admin_role_id`, `role_id`.
     *
     * @param string $bare Table name without the site prefix.
     * @return list<string>
     */
    private static function guessedColumns(string $bare): array
    {
        $columns = [$bare . '_id'];
        $cut = strpos($bare, '_');
        if ($cut !== false && $cut < strlen($bare) - 1) {
            $columns[] = substr($bare, $cut + 1) . '_id';
        }

        return $columns;
    }

    /**
     * @param string $table Real name.
     * @return string Name without the site prefix.
     */
    private static function bare(string $table): string
    {
        return GP247_DB_PREFIX !== '' && str_starts_with($table, GP247_DB_PREFIX) ? substr($table, strlen(GP247_DB_PREFIX)) : $table;
    }

    /**
     * @param string $iso Swap time (ISO 8601).
     * @return string The same instant in the application timezone, as the database stores timestamps; '' when unknown.
     */
    private static function localTime(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        try {
            return Carbon::parse($iso)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }
}
