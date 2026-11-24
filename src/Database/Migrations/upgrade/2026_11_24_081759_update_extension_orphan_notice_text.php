<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The notice of an orphaned extension (installed in the database, source missing) now offers
 * two ways out: copy the source of the same or a newer version and apply the data update, or
 * forget the registration. Only rows still holding the exact old default text are replaced —
 * a translation the site owner edited is kept. New labels of the same change come from
 * DataLanguageSeeder / Locales/ja.php (gp247:core-update), not from here.
 *
 * Idempotent: a second run finds no old text left.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-orphan-source-missing-notice
 * @aidlc-adr plugin-manager_orphan-forget-registration
 */
return new class extends Migration
{
    private const CODE = 'admin.extension.orphan_source_missing';

    /**
     * Default text shipped before this change, per location.
     *
     * @return array<string, string>
     */
    public function oldTexts(): array
    {
        return [
            'en' => '":key" is installed in the database (data version :version) but this site has no source for it. GP247 does not install over existing data: copy app/GP247/:type/:key and public/GP247/:type/:key from the site this database came from (same version :version). If you can only copy app/, run "php artisan gp247:ext-publish --type=:cli_type --key=:key".',
            'vi' => '":key" đã cài trong cơ sở dữ liệu (dữ liệu phiên bản :version) nhưng site này không có mã nguồn. GP247 không cài đè lên dữ liệu đang có: hãy chép thư mục app/GP247/:type/:key và public/GP247/:type/:key từ site gốc của cơ sở dữ liệu này (đúng phiên bản :version). Nếu chỉ chép được app/, chạy "php artisan gp247:ext-publish --type=:cli_type --key=:key".',
            'ja' => '「:key」はデータベースにインストールされています（データバージョン :version）が、このサイトにはソースがありません。GP247 は既存のデータに上書きインストールしません。このデータベースの元のサイトから app/GP247/:type/:key と public/GP247/:type/:key をコピーしてください（同じバージョン :version）。app/ のみコピーできる場合は "php artisan gp247:ext-publish --type=:cli_type --key=:key" を実行してください。',
        ];
    }

    /**
     * Default text from this change on (same as DataLanguageSeeder / Locales/ja.php).
     *
     * @return array<string, string>
     */
    public function newTexts(): array
    {
        return [
            'en' => '":key" is installed in the database (data version :version) but this site has no source for it. GP247 does not install over existing data. Way 1: copy app/GP247/:type/:key and public/GP247/:type/:key of the same version or a newer one (the newer version must allow updating from :version), then click "Apply data update" on the :type screen or run "php artisan gp247:update". If you can only copy app/, run "php artisan gp247:ext-publish --type=:cli_type --key=:key". Way 2: if you no longer need it, click "Forget" on the :type screen or run "php artisan gp247:ext-uninstall --type=:cli_type --key=:key --forget".',
            'vi' => '":key" đã cài trong cơ sở dữ liệu (dữ liệu phiên bản :version) nhưng site này không có mã nguồn. GP247 không cài đè lên dữ liệu đang có. Cách 1: chép thư mục app/GP247/:type/:key và public/GP247/:type/:key của cùng phiên bản hoặc bản mới hơn (bản mới phải cho phép nâng từ :version), rồi bấm "Áp dụng cập nhật dữ liệu" trên màn :type hoặc chạy "php artisan gp247:update". Nếu chỉ chép được app/, chạy "php artisan gp247:ext-publish --type=:cli_type --key=:key". Cách 2: nếu không cần nữa, bấm "Gỡ đăng ký" trên màn :type hoặc chạy "php artisan gp247:ext-uninstall --type=:cli_type --key=:key --forget".',
            'ja' => '「:key」はデータベースにインストールされています（データバージョン :version）が、このサイトにはソースがありません。GP247 は既存のデータに上書きインストールしません。方法1：同じバージョンまたはより新しいバージョン（:version からの更新に対応しているもの）の app/GP247/:type/:key と public/GP247/:type/:key をコピーし、:type 画面で「データ更新を適用」をクリックするか "php artisan gp247:update" を実行してください。app/ のみコピーできる場合は "php artisan gp247:ext-publish --type=:cli_type --key=:key" を実行してください。方法2：不要になった場合は :type 画面で「登録解除」をクリックするか "php artisan gp247:ext-uninstall --type=:cli_type --key=:key --forget" を実行してください。',
        ];
    }

    /**
     * Replace the untouched old default text with the new one.
     *
     * @return void
     */
    public function up(): void
    {
        $table = GP247_DB_PREFIX . 'languages';
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable($table)) {
            return;
        }
        $new = $this->newTexts();
        foreach ($this->oldTexts() as $location => $old) {
            DB::connection(GP247_DB_CONNECTION)->table($table)
                ->where('code', self::CODE)
                ->where('location', $location)
                ->where('text', $old)
                ->update(['text' => $new[$location]]);
        }
    }

    /**
     * Nothing to undo: the old text gave a single, narrower way out.
     *
     * @return void
     */
    public function down(): void
    {
    }
};
