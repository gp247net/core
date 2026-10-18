<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade: seed the labels of the "Check before use" box of plugin settings
 * screens for sites installed before US-admin-shell-config-form-readiness. Fresh installs
 * get them from DataLanguageSeeder; insertOrIgnore keeps any text a site owner edited.
 *
 * Runs via gp247:core-update (--path upgrade/), never the create-tables migration.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-config-form-readiness
 */
return new class extends Migration
{
    /**
     * Label rows shipped by this migration (same text as DataLanguageSeeder).
     *
     * @return array<int, array<string, string>>
     */
    public static function rows(): array
    {
        $p = 'admin.config_form';

        return [
            ['code' => 'admin.config_form.readiness_title', 'text' => 'Check before use', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.config_form.readiness_title', 'text' => 'Kiểm tra trước khi dùng', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.config_form.readiness_all_ok', 'text' => 'Everything this plugin needs is in place.', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.config_form.readiness_all_ok', 'text' => 'Đã đủ điều kiện để plugin hoạt động.', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.config_form.readiness_failed', 'text' => 'Could not check the conditions this plugin needs. See the system log.', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.config_form.readiness_failed', 'text' => 'Không kiểm tra được điều kiện của plugin. Xem nhật ký hệ thống.', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.config_form.readiness_passed', 'text' => 'Already in place', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.config_form.readiness_passed', 'text' => 'Đã đạt', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.config_form.readiness_fix', 'text' => 'Fix', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.config_form.readiness_fix', 'text' => 'Sửa', 'position' => $p, 'location' => 'vi'],
        ];
    }

    /**
     * @return void
     */
    public function up()
    {
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX.'languages')
            ->insertOrIgnore(self::rows());
    }

    /**
     * WARNING: removes these label rows, including any text a site owner edited.
     *
     * @return void
     */
    public function down()
    {
        $codes = array_unique(array_column(self::rows(), 'code'));
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX.'languages')
            ->whereIn('code', $codes)
            ->delete();
    }
};
