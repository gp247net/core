<?php

namespace GP247\Core\PersonalData\Domain\Export;

use GP247\Core\PersonalData\Domain\PersonalDataSource;

/**
 * What a customer's data package may contain, and how its cells are written. A source puts in
 * the package only the columns it lists for export; on top of that, a column whose name looks
 * like a secret (password, token, two-factor secret, API key) is never exported, even when a
 * declaration lists it by mistake.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class ExportFormat
{
    /** Column names never exported, whatever a declaration says. */
    public const SECRET_PATTERN = '/(password|passwd|token|secret|remember|two_factor|2fa|otp|api_key|apikey|private_key|recovery)/i';

    /** Characters that make a spreadsheet read a cell as a formula. */
    private const FORMULA_START = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param string $column
     * @return bool
     */
    public static function isSecret(string $column): bool
    {
        return preg_match(self::SECRET_PATTERN, $column) === 1;
    }

    /**
     * @param PersonalDataSource $source
     * @return list<string> Columns of this source placed in the package.
     */
    public static function columnsOf(PersonalDataSource $source): array
    {
        return array_values(array_filter($source->exportColumns(), fn (string $c) => !self::isSecret($c)));
    }

    /**
     * A value as one spreadsheet cell: text, never a formula.
     *
     * @param mixed $value
     * @return string
     */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $text = (string) $value;
        if ($text !== '' && in_array($text[0], self::FORMULA_START, true) && !is_numeric($text)) {
            // WHY: a cell starting with = + - @ runs as a formula in Excel (CSV injection).
            $text = "'" . $text;
        }

        return $text;
    }

    /**
     * @param list<mixed> $cells
     * @return string One CSV line (RFC 4180 quoting), ending with CRLF.
     */
    public static function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $text = self::cell($cell);
            $out[] = preg_match('/[",\r\n]/', $text) === 1 ? '"' . str_replace('"', '""', $text) . '"' : $text;
        }

        return implode(',', $out) . "\r\n";
    }

    /**
     * @param string $table Logical table name.
     * @return string Safe file name of the table in the package.
     */
    public static function fileName(string $table): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $table) ?: 'data';
    }
}
