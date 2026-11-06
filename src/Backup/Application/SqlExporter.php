<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Format\RowCodec;

/**
 * Writes the tables of an opened archive as a plain `.sql` file of INSERT statements — for a
 * person who wants to read the data with ordinary tools (US-backup-engine-safe-format). The file
 * is NOT encrypted: the command line warns and asks first, and there is no web route to it.
 *
 * Statements create no table and run nothing else: one INSERT per batch of rows, names checked
 * (RowCodec::validName) and quoted, every value written as a literal no SQL dialect reads
 * differently (see quote()).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-safe-format, US-backup-admin-cli
 * @aidlc-adr backup-engine_archive-format
 */
final class SqlExporter
{
    private const BATCH = 200;


    /**
     * @param ArchiveReader $reader
     * @param string        $file Output path (must not exist; created with mode 0600).
     * @return array{tables: int, rows: int, bytes: int}
     * @throws \RuntimeException When the file exists or cannot be written (a partial file is removed).
     *
     * @aidlc-story US-backup-engine-safe-format
     */
    public function export(ArchiveReader $reader, string $file): array
    {
        if (file_exists($file)) {
            throw new \RuntimeException('The output file already exists.');
        }
        $out = @fopen($file, 'xb');
        if ($out === false) {
            throw new \RuntimeException('The output file cannot be created.');
        }
        @chmod($file, 0600);
        $tables = 0;
        $rows = 0;
        $complete = false;
        try {
            $manifest = $reader->manifest();
            // Only checked id / date in the comment, control characters removed anyway.
            $label = preg_replace('/[^0-9A-Za-z:+.\- TZ]/', '', $manifest['archive_id'] . ' ' . $manifest['created_at']);
            fwrite($out, "-- GP247 backup " . $label . "\n-- Plain data, not encrypted. INSERT statements only.\nSET NAMES utf8mb4;\n\n");
            foreach (array_keys((array) $manifest['tables']) as $table) {
                $table = (string) $table;
                if (!RowCodec::validName($table)) {
                    continue;
                }
                $tables++;
                $rows += $this->table($out, $reader, $table, (array) $manifest['tables'][$table]['columns']);
            }
            $complete = true;
        } finally {
            fclose($out);
            if (!$complete) {
                // A half-written plain copy of personal data is never left behind.
                @unlink($file);
            }
        }

        return ['tables' => $tables, 'rows' => $rows, 'bytes' => (int) filesize($file)];
    }

    /**
     * @param resource      $out
     * @param ArchiveReader $reader
     * @param string        $table
     * @param list<string>  $columns
     * @return int Rows written.
     */
    private function table($out, ArchiveReader $reader, string $table, array $columns): int
    {
        $columns = array_values(array_filter(array_map('strval', $columns), [RowCodec::class, 'validName']));
        if ($columns === []) {
            return 0;
        }
        $head = 'INSERT INTO `' . $table . '` (' . implode(', ', array_map(fn ($c) => '`' . $c . '`', $columns)) . ') VALUES' . "\n";
        $batch = [];
        $count = 0;
        fwrite($out, '-- ' . $table . "\n");
        foreach ($reader->rows($table) as $row) {
            $batch[] = '(' . implode(', ', array_map(fn ($c) => $this->value($row[$c] ?? null), $columns)) . ')';
            $count++;
            if (count($batch) >= self::BATCH) {
                fwrite($out, $head . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch !== []) {
            fwrite($out, $head . implode(",\n", $batch) . ";\n");
        }
        fwrite($out, "\n");

        return $count;
    }

    /**
     * @param mixed $value Decoded value (binary values arrive as raw bytes).
     * @return string SQL literal.
     */
    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) && is_finite($value) => (string) $value,
            !is_string($value) => self::quote((string) json_encode($value)),
            default => self::quote($value),
        };
    }

    /**
     * Dialect-proof literal: a string with a backslash, a NUL, any control character or invalid
     * UTF-8 is written as hex (X'…'), so no server setting (MySQL backslash escapes on or off,
     * SQLite) can change where it ends; others are quoted with the quote doubled.
     *
     * @param string $value
     * @return string
     */
    private static function quote(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\\\\\x00-\x1f\x7f]/', $value) === 1) {
            return "X'" . bin2hex($value) . "'";
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }
}
