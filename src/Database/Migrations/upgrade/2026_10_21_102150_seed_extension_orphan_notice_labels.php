<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade: seed the labels that explain an "orphaned" extension —
 * installed in the database but with no source on this site — for sites installed
 * before US-PLG-orphan-source-missing-notice. Fresh installs get them from
 * DataLanguageSeeder; insertOrIgnore keeps any text a site owner already edited.
 *
 * Runs via gp247:core-update (--path upgrade/), never the create-tables migration.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-orphan-source-missing-notice
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
            ['code' => 'admin.extension.orphan_badge', 'text' => 'Source missing', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.orphan_badge', 'text' => 'Thiếu mã nguồn', 'position' => $p, 'location' => 'vi'],
            ['code' => 'admin.extension.orphan_source_missing', 'text' => '":key" is installed in the database (data version :version) but this site has no source for it. GP247 does not install over existing data: copy app/GP247/:type/:key and public/GP247/:type/:key from the site this database came from (same version :version). If you can only copy app/, run "php artisan gp247:ext-publish --type=:cli_type --key=:key".', 'position' => $p, 'location' => 'en'],
            ['code' => 'admin.extension.orphan_source_missing', 'text' => '":key" đã cài trong cơ sở dữ liệu (dữ liệu phiên bản :version) nhưng site này không có mã nguồn. GP247 không cài đè lên dữ liệu đang có: hãy chép thư mục app/GP247/:type/:key và public/GP247/:type/:key từ site gốc của cơ sở dữ liệu này (đúng phiên bản :version). Nếu chỉ chép được app/, chạy "php artisan gp247:ext-publish --type=:cli_type --key=:key".', 'position' => $p, 'location' => 'vi'],
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
