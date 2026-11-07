<?php

namespace GP247\Core\Support\ListSearch;

/**
 * Language rows (en / vi / ja) of the admin list search box — one source for the
 * install seeder and the upgrade migration.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-list-search-scope-hint
 * @aidlc-adr ADR-005
 */
final class ListSearchLanguage
{
    /**
     * Texts keyed by language code, then locale.
     *
     * @return array<string, array{en: string, vi: string, ja: string}>
     */
    public static function texts(): array
    {
        return [
            'admin.search.by' => ['en' => 'Search by :columns', 'vi' => 'Tìm theo :columns', 'ja' => ':columns で検索'],
            'admin.search.in' => ['en' => 'Searching in: :columns', 'vi' => 'Tìm trong: :columns', 'ja' => '検索対象: :columns'],
            'admin.search.no_result' => [
                'en' => 'No results for ":keyword" in :columns.',
                'vi' => 'Không tìm thấy ":keyword" trong :columns.',
                'ja' => '「:keyword」は :columns に見つかりません。',
            ],
            'admin.search.clear' => ['en' => 'Clear search', 'vi' => 'Xoá từ khoá', 'ja' => '検索をクリア'],
            // WHY: plain column names for the hint — the form labels of these columns
            // (e.g. product.alias) carry HTML badges that cannot go in a placeholder.
            'admin.search.col_alias' => ['en' => 'URL alias', 'vi' => 'Đường dẫn (alias)', 'ja' => 'URLエイリアス'],
            'admin.search.col_id' => ['en' => 'ID', 'vi' => 'ID', 'ja' => 'ID'],
        ];
    }

    /**
     * Rows ready for the languages table.
     *
     * @return array<int, array{code: string, text: string, position: string, location: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::texts() as $code => $byLocale) {
            foreach ($byLocale as $location => $text) {
                $rows[] = ['code' => $code, 'text' => $text, 'position' => 'admin.search', 'location' => $location];
            }
        }

        return $rows;
    }
}
