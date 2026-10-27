<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REST API switches of the API management screen (US-CMP-api-admin-switch), delivered
 * to installed sites through gp247:update:
 * - the five switch keys, all on, so an upgraded site keeps serving v1 (a fresh install
 *   gets v1 off from DataDefaultSeeder); a key the site already has is never overwritten;
 * - the screen's new labels (en/vi/ja), plus apikey_keep_hint / apikey_once which the
 *   3.2.3 release only shipped to fresh installs.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-switch, US-CMP-api-docs-link-from-admin
 */
return new class extends Migration
{
    /** @var string[] Switch keys, master first. */
    private const SWITCH_KEYS = ['api_enabled', 'api_v1_core', 'api_v1_front', 'api_v2_core', 'api_v2_front'];

    /**
     * Label rows (code, text, position, location).
     *
     * @return array<int, array<string, string>>
     */
    public static function rows(): array
    {
        $labels = [
            'switch_all' => ['REST API (all groups)', 'REST API (tất cả nhóm)', 'REST API（全グループ）'],
            'switch_core' => ['Admin API (core)', 'API quản trị (core)', '管理API (core)'],
            'switch_front' => ['Public & customer API (front)', 'API công khai & khách hàng (front)', '公開・顧客API (front)'],
            'switch_locked' => [
                'The API is turned off in the server configuration (GP247_API_MODE=0). Ask your hosting provider or developer to turn it on.',
                'API đang tắt ở cấu hình máy chủ (GP247_API_MODE=0). Nhờ nhà cung cấp hosting hoặc lập trình viên bật lại.',
                'APIはサーバー設定でオフになっています (GP247_API_MODE=0)。ホスティング事業者または開発者にオンにするよう依頼してください。',
            ],
            'switch_help' => [
                'A group that is off answers 404 with the code api_disabled.',
                'Nhóm đang tắt trả 404 với mã api_disabled.',
                'オフのグループは 404（コード api_disabled）を返します。',
            ],
            'group_off' => ['off', 'đang tắt', 'オフ'],
            'group_other' => ['Other (plugins)', 'Khác (plugin)', 'その他（プラグイン）'],
            'route_list_note' => [
                'Routes controlled by the switches above. A plugin with its own routes (e.g. MCP server) has its own switch.',
                'Các route chịu công tắc ở trên. Plugin có route riêng (vd MCP server) có công tắc riêng.',
                '上のスイッチで制御されるルートです。独自のルートを持つプラグイン（例: MCPサーバー）には専用のスイッチがあります。',
            ],
            'docs_guide' => ['API guide', 'Hướng dẫn API', 'APIガイド'],
            // Shipped by 3.2.3 to fresh installs only.
            'apikey_keep_hint' => [
                'Leave empty to keep the current key. A saved key cannot be viewed again.',
                'Để trống để giữ khoá hiện tại. Khoá đã lưu không thể xem lại.',
                '現在のキーを保持する場合は空のままにしてください。保存したキーは再表示できません。',
            ],
            'apikey_once' => [
                'Copy this API key now — it is shown only this once:',
                'Sao chép khoá API ngay — đây là lần duy nhất khoá được hiển thị:',
                'このAPIキーを今すぐコピーしてください。表示されるのはこの一回だけです:',
            ],
        ];

        $rows = [];
        foreach ($labels as $code => [$en, $vi, $ja]) {
            foreach (['en' => $en, 'vi' => $vi, 'ja' => $ja] as $location => $text) {
                $rows[] = ['code' => 'admin.api_connection.' . $code, 'text' => $text, 'position' => 'admin.api_connection', 'location' => $location];
            }
        }
        return $rows;
    }

    /**
     * @return void
     */
    public function up()
    {
        $db = DB::connection(GP247_DB_CONNECTION);

        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'admin_config')) {
            $sort = 2;
            $config = [];
            foreach (self::SWITCH_KEYS as $key) {
                $config[] = [
                    'group' => 'global', 'code' => 'api_config', 'key' => $key, 'value' => '1', 'sort' => $sort++,
                    'detail' => $key === 'api_enabled' ? 'admin.api_connection.switch_all'
                        : (str_ends_with($key, '_core') ? 'admin.api_connection.switch_core' : 'admin.api_connection.switch_front'),
                    'store_id' => GP247_STORE_ID_GLOBAL,
                ];
            }
            // WHY insertOrIgnore: unique (key, store_id) keeps a value the site already chose.
            $db->table(GP247_DB_PREFIX . 'admin_config')->insertOrIgnore($config);
        }

        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            $db->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(self::rows());
        }
    }

    /**
     * WARNING: deletes the switch keys, losing the site's choice (a missing key counts
     * as on, so the API serves every group again). Label rows are left in place.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'admin_config')) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
                ->where('store_id', GP247_STORE_ID_GLOBAL)
                ->whereIn('key', self::SWITCH_KEYS)
                ->delete();
        }
    }
};
