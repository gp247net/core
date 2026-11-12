<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Labels of the "GP247 versions" block on the Server info screen
 * (US-admin-shell-server-info-gp247-versions), delivered to installed sites through
 * gp247:update. A fresh install gets the same rows from DataLanguageSeeder / Locales/ja.php.
 * Idempotent; a translation the site already edited is never overwritten.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-server-info-gp247-versions
 */
return new class extends Migration
{
    /**
     * Label rows (code, text, position, location).
     *
     * @return array<int, array<string, string>>
     */
    public static function rows(): array
    {
        $texts = [
            'admin.server_info_gp247_title' => [
                'en' => 'GP247 versions',
                'vi' => 'Phiên bản GP247',
                'ja' => 'GP247 のバージョン',
            ],
            'admin.server_info_gp247_hint' => [
                'en' => 'Compare each line with the "Recommended minimum" column under "Recommended versions" on the S-Cart release page. A lower version means this site is missing fixes.',
                'vi' => 'So từng dòng với cột "Tối thiểu khuyến nghị" ở mục "Phiên bản nên dùng" trên trang phát hành S-Cart. Thấp hơn nghĩa là site đang thiếu bản sửa lỗi.',
                'ja' => 'S-Cart のリリースページ「推奨バージョン」の「推奨最低バージョン」列と各行を比べてください。低い場合、このサイトには修正が不足しています。',
            ],
        ];

        $rows = [];
        foreach ($texts as $code => $byLocation) {
            foreach ($byLocation as $location => $text) {
                $rows[] = ['code' => $code, 'text' => $text, 'position' => 'admin', 'location' => $location];
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
