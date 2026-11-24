<?php

namespace GP247\Core\PersonalData\Application\Erasure;

use GP247\Core\PersonalData\Application\SourceQuery;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\KnownValues;
use GP247\Core\PersonalData\Domain\PersonalDataSource;

/**
 * Applies the column rules of one source to a chunk of its rows: constant rules (empty, marker)
 * in one UPDATE, free text and JSON row by row. Columns without a rule are never touched.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-personal-data-registry-free-text-scrub
 * @aidlc-adr personal-data-registry_anonymisation-rules
 */
final class Anonymiser
{
    /** @var array<string, array<string, bool>> Real table => column => nullable. */
    private static array $nullable = [];

    /**
     * @param PersonalDataSource $source
     * @param list<string|int>   $keys  Rows of the subject in this chunk.
     * @param KnownValues        $known
     * @return int Rows handled.
     */
    public function apply(PersonalDataSource $source, array $keys, KnownValues $known): int
    {
        if ($keys === []) {
            return 0;
        }
        $table = GP247_DB_PREFIX . $source->table;
        $db = SourceQuery::db();
        $constant = [];
        $scrub = [];
        foreach ($source->columns() as $column => $rule) {
            if (!$this->hasColumn($table, $column)) {
                continue;
            }
            if ($rule->isConstant()) {
                $constant[$column] = $rule->kind === ColumnRule::MARK ? ColumnRule::MARKER : $this->emptyValue($table, $column);
            } elseif ($rule->kind !== ColumnRule::KEEP) {
                $scrub[$column] = $rule;
            }
        }
        $db->transaction(function () use ($db, $table, $source, $keys, $constant, $scrub, $known) {
            if ($constant !== []) {
                $db->table($table)->whereIn($source->key, $keys)->update($constant);
            }
            if ($scrub !== []) {
                $this->scrubRows($table, $source->key, $keys, $scrub, $known);
            }
        });

        return count($keys);
    }

    /**
     * @param string                    $table
     * @param string                    $key
     * @param list<string|int>          $keys
     * @param array<string, ColumnRule> $rules
     * @param KnownValues               $known
     * @return void
     */
    private function scrubRows(string $table, string $key, array $keys, array $rules, KnownValues $known): void
    {
        $db = SourceQuery::db();
        $rows = $db->table($table)->whereIn($key, $keys)->get(array_merge([$key], array_keys($rules)));
        foreach ($rows as $row) {
            $changes = [];
            foreach ($rules as $column => $rule) {
                $value = $row->{$column} ?? null;
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $new = $rule->kind === ColumnRule::SCRUB_JSON ? JsonScrubber::scrub($value, $rule->keys, $known) : $known->scrub($value);
                if ($new !== $value) {
                    $changes[$column] = $new;
                }
            }
            if ($changes !== []) {
                $db->table($table)->where($key, $row->{$key})->update($changes);
            }
        }
    }

    /**
     * @param string $table
     * @param string $column
     * @return bool
     */
    private function hasColumn(string $table, string $column): bool
    {
        return array_key_exists($column, $this->columns($table));
    }

    /**
     * @param string $table
     * @param string $column
     * @return string|null NULL when the column allows it, otherwise ''.
     */
    private function emptyValue(string $table, string $column): ?string
    {
        return ($this->columns($table)[$column] ?? true) ? null : '';
    }

    /**
     * @param string $table
     * @return array<string, bool> Column => nullable.
     */
    private function columns(string $table): array
    {
        if (!isset(self::$nullable[$table])) {
            $map = [];
            foreach (SourceQuery::db()->getSchemaBuilder()->getColumns($table) as $column) {
                $map[(string) $column['name']] = (bool) ($column['nullable'] ?? true);
            }
            self::$nullable[$table] = $map;
        }

        return self::$nullable[$table];
    }

    /**
     * @return void Forget the cached column lists (tests that change a schema).
     */
    public static function forgetColumns(): void
    {
        self::$nullable = [];
    }
}
