<?php

namespace GP247\Core\Database\Seeders;

/**
 * Build language-table rows for an extra default locale from a per-locale data
 * file (`Locales/<locale>.php`, a plain `code => text` map).
 *
 * Shared by the core/front/shop language seeders and their upgrade migrations so
 * a locale's translations live in exactly one place (ADR
 * compat-foundation_locale-data-files). The `position` of each row is copied from
 * the 'en' row of the same code; codes without an 'en' row are dropped.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-japanese-default-locale
 * @aidlc-adr compat-foundation_locale-data-files
 */
class LocaleRows
{
    /**
     * Turn a locale data file into rows matching the language table shape.
     *
     * @param string $file Absolute path of the `code => text` data file.
     * @param string $locale Location value written on each row (e.g. 'ja').
     * @param array<int, array<string, mixed>> $baseRows Seeder rows holding the 'en' originals.
     * @return array<int, array{code: string, text: string, position: string, location: string}>
     */
    public static function fromFile(string $file, string $locale, array $baseRows): array
    {
        if (!is_file($file)) {
            return [];
        }
        $texts = require $file;
        if (!is_array($texts)) {
            return [];
        }

        $positions = [];
        foreach ($baseRows as $row) {
            if (($row['location'] ?? null) === 'en') {
                $positions[$row['code']] = $row['position'];
            }
        }

        $rows = [];
        foreach ($texts as $code => $text) {
            if (!array_key_exists($code, $positions)) {
                continue;
            }
            $rows[] = [
                'code' => (string) $code,
                'text' => (string) $text,
                'position' => (string) $positions[$code],
                'location' => $locale,
            ];
        }

        return $rows;
    }
}
