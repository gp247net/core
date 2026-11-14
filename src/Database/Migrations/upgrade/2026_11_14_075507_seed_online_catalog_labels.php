<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Labels of the online extension library (whole catalogue with the core an item needs,
 * compatibility filter, "All" group chip, empty filter result), delivered to installed
 * sites through gp247:update. A fresh install gets the same rows from
 * DataLanguageSeeder / Locales/ja.php. Idempotent; a translation the site already edited
 * is never overwritten.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-online-catalog-all-cores
 * @aidlc-story US-PLG-config-code-filter
 */
return new class extends Migration
{
    /**
     * Label texts by code and location (same text as DataLanguageSeeder / Locales/ja.php).
     *
     * @return array<string, array<string, string>>
     */
    public static function texts(): array
    {
        return [
            'admin.extension.compat_all' => [
                'en' => 'All cores',
                'vi' => 'Mọi phiên bản core',
                'ja' => 'すべてのコア',
            ],
            'admin.extension.compat_only' => [
                'en' => 'Runs on my core (:current)',
                'vi' => 'Chạy được trên core của tôi (:current)',
                'ja' => '現在のコアで動作 (:current)',
            ],
            'admin.extension.requires_core' => [
                'en' => 'Needs core :version+ (running :current)',
                'vi' => 'Cần core :version+ (đang chạy :current)',
                'ja' => 'コア :version 以上が必要（現在 :current）',
            ],
            'admin.extension.unsupported_core' => [
                'en' => 'Not available for core :current',
                'vi' => 'Chưa hỗ trợ core :current',
                'ja' => 'コア :current には未対応',
            ],
            'admin.extension.incompatible_hint' => [
                'en' => 'This extension cannot be installed on core :current. Update GP247 (composer update, then php artisan gp247:update) to a core it supports, or open the product page for details.',
                'vi' => 'Không cài được extension này trên core :current. Hãy nâng GP247 (composer update, rồi php artisan gp247:update) lên bản core mà nó hỗ trợ, hoặc mở trang sản phẩm để xem chi tiết.',
                'ja' => 'この拡張機能はコア :current にはインストールできません。対応するコアまで GP247 を更新する（composer update の後に php artisan gp247:update）か、製品ページで詳細を確認してください。',
            ],
            'admin.extension.filter_all' => [
                'en' => 'All',
                'vi' => 'Tất cả',
                'ja' => 'すべて',
            ],
            'admin.extension.filter_empty' => [
                'en' => 'No plugin in the selected groups.',
                'vi' => 'Không có plugin nào trong nhóm đã chọn.',
                'ja' => '選択したグループにプラグインはありません。',
            ],
        ];
    }

    /**
     * Label rows (code, text, position, location).
     *
     * @return array<int, array<string, string>>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::texts() as $code => $byLocation) {
            foreach ($byLocation as $location => $text) {
                $rows[] = ['code' => $code, 'text' => $text, 'position' => 'admin.extension', 'location' => $location];
            }
        }

        return $rows;
    }

    /**
     * @return void
     */
    public function up()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            // WHY insertOrIgnore: unique (code, location) keeps a translation the site already edited.
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(self::rows());
        }
    }

    /**
     * Label rows are left in place (a missing label only shows its code).
     *
     * @return void
     */
    public function down()
    {
    }
};
