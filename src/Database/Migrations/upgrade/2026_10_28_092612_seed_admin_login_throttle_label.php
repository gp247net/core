<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Message shown by the admin login form once the attempt limit is reached
 * (US-AUI-admin-login-throttle), delivered to installed sites through gp247:update.
 * A fresh install gets the same rows from DataLanguageSeeder / Locales/ja.php.
 * Idempotent; a translation the site already edited is never overwritten.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-admin-login-throttle
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
            'en' => 'Too many login attempts. Please try again in :seconds seconds.',
            'vi' => 'Đăng nhập sai quá nhiều lần. Vui lòng thử lại sau :seconds giây.',
            'ja' => 'ログインの試行回数が多すぎます。:seconds 秒後にもう一度お試しください。',
        ];

        $rows = [];
        foreach ($texts as $location => $text) {
            $rows[] = ['code' => 'admin.login_too_many_attempts', 'text' => $text, 'position' => 'admin', 'location' => $location];
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
