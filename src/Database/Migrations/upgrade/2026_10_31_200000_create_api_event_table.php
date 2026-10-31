<?php

use GP247\Core\Api\Sync\ApiSyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event log for ERP sync: the table, its on/off switch — OFF on fresh installs and on
 * upgrades, a site's own choice is never overwritten — and the switch labels on the API
 * screen (en / vi / ja; a fresh install gets the same rows from DataDefaultSeeder,
 * DataLanguageSeeder and Locales/ja.php). Idempotent and add-only.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-record, US-api-sync-event-retention
 * @aidlc-adr api-sync_event-log-after-commit
 */
return new class extends Migration
{
    /**
     * Label rows of the switch.
     *
     * @return array<int, array<string, string>>
     */
    public static function labels(): array
    {
        $texts = [
            'admin.api_connection.event_log' => [
                'en' => 'Event log for sync (ERP)',
                'vi' => 'Nhật ký sự kiện cho đồng bộ (ERP)',
                'ja' => '同期用イベントログ（ERP）',
            ],
            'admin.api_connection.event_log_help' => [
                'en' => 'Records changes of orders, stock, products and customers so a sync tool can read them through api/v2/core/events. Kept 30 days. Leave off when no ERP reads it.',
                'vi' => 'Ghi lại thay đổi của đơn hàng, tồn kho, sản phẩm và khách hàng để công cụ đồng bộ đọc qua api/v2/core/events. Giữ 30 ngày. Để tắt khi không có ERP nào đọc.',
                'ja' => '注文・在庫・商品・顧客の変更を記録し、同期ツールが api/v2/core/events で読めるようにします。30 日間保持します。ERP が読まない場合はオフのままにしてください。',
            ],
        ];
        $rows = [];
        foreach ($texts as $code => $byLocation) {
            foreach ($byLocation as $location => $text) {
                $rows[] = ['code' => $code, 'text' => $text, 'position' => 'admin.api_connection', 'location' => $location];
            }
        }
        return $rows;
    }

    /**
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        ApiSyncSchema::create($schema);
        $db = DB::connection(GP247_DB_CONNECTION);
        if ($schema->hasTable(GP247_DB_PREFIX . 'admin_config')) {
            $db->table(GP247_DB_PREFIX . 'admin_config')->insertOrIgnore([
                'group' => 'global', 'code' => 'api_config', 'key' => 'api_event_log', 'value' => '0',
                'sort' => 7, 'detail' => 'admin.api_connection.event_log', 'store_id' => GP247_STORE_ID_GLOBAL,
            ]);
        }
        if ($schema->hasTable(GP247_DB_PREFIX . 'languages')) {
            // WHY insertOrIgnore: unique (code, location) keeps a translation the site already edited.
            $db->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(self::labels());
        }
    }

    /**
     * WARNING: drops the event table — every recorded event is lost and readers must resync.
     * The switch row and labels are left in place.
     *
     * @return void
     */
    public function down()
    {
        ApiSyncSchema::dropEventLog(Schema::connection(GP247_DB_CONNECTION));
    }
};
