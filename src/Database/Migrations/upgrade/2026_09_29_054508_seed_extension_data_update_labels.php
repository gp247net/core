<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade: seed the labels of the "data update pending" badge and the
 * "Apply data update" button on the Extension screen for sites installed before
 * US-PLG-local-update-data-converge. Fresh installs get them from DataLanguageSeeder;
 * insertOrIgnore keeps any text a site owner already edited.
 *
 * Runs via gp247:core-update (--path upgrade/), never the create-tables migration.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-local-update-data-converge
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
        $p = 'admin.extension';

        return [
            ['code' => 'admin.extension.data_pending', 'text' => 'Data update pending: :from → :to', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.data_pending', 'text' => 'Chờ cập nhật dữ liệu: :from → :to', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.extension.data_pending_hint', 'text' => 'The files were updated outside the marketplace (git, composer, FTP). Apply to run the data update of the new version.', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.data_pending_hint', 'text' => 'File đã được cập nhật ngoài marketplace (git, composer, FTP). Bấm Áp dụng để chạy cập nhật dữ liệu của bản mới.', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.extension.apply_data', 'text' => 'Apply data update', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.apply_data', 'text' => 'Áp dụng cập nhật dữ liệu', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.extension.apply_data_success', 'text' => ':key data updated to version :version', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.apply_data_success', 'text' => 'Đã cập nhật dữ liệu :key lên phiên bản :version', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.extension.apply_data_failed', 'text' => 'Data update of :key failed: :msg', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.apply_data_failed', 'text' => 'Cập nhật dữ liệu :key thất bại: :msg', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.extension.apply_data_nothing', 'text' => ':key is already up to date', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.apply_data_nothing', 'text' => ':key đã ở phiên bản mới nhất', 'position' => $p, 'location' => 'vi'],
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
        DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX.'languages')
            ->whereIn('code', array_values(array_unique(array_column(self::rows(), 'code'))))
            ->delete();
    }
};
