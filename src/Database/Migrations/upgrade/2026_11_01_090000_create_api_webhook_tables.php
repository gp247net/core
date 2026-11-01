<?php

use GP247\Core\Api\Sync\ApiSyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhooks for ERP sync: the registration and delivery tables, and the labels of the
 * webhooks block on the API screen (en / vi / ja; a fresh install gets the same rows from
 * DataLanguageSeeder and Locales/ja.php). Idempotent and add-only.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-register, US-api-sync-webhook-delivery
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
return new class extends Migration
{
    /**
     * Label rows of the webhooks block.
     *
     * @return array<int, array<string, string>>
     */
    public static function labels(): array
    {
        $texts = [
            'admin.api_webhook.title' => [
                'en' => 'Webhooks for sync (ERP)',
                'vi' => 'Webhook cho đồng bộ (ERP)',
                'ja' => '同期用 Webhook（ERP）',
            ],
            'admin.api_webhook.help' => [
                'en' => 'Sends each sync event of the chosen types to your URL right after it happens, signed with the webhook secret (header X-GP247-Signature). Needs the event log on. Failed sends are retried for about 15 hours; read api/v2/core/events to catch up anything missed.',
                'vi' => 'Gửi từng sự kiện thuộc các loại đã chọn tới URL của bạn ngay sau khi xảy ra, có chữ ký bằng khoá của webhook (header X-GP247-Signature). Cần bật nhật ký sự kiện. Lần gửi lỗi được thử lại trong khoảng 15 giờ; đọc api/v2/core/events để bù phần bị sót.',
                'ja' => '選択した種類の同期イベントを、発生直後に指定 URL へ送信します（Webhook の秘密鍵で署名、ヘッダー X-GP247-Signature）。イベントログをオンにする必要があります。失敗した送信は約 15 時間再試行されます。取りこぼしは api/v2/core/events で補ってください。',
            ],
            'admin.api_webhook.url' => [
                'en' => 'Receiving URL (https)',
                'vi' => 'URL nhận (https)',
                'ja' => '受信 URL（https）',
            ],
            'admin.api_webhook.event_types' => [
                'en' => 'Event types',
                'vi' => 'Loại sự kiện',
                'ja' => 'イベントの種類',
            ],
            'admin.api_webhook.status' => [
                'en' => 'Enabled',
                'vi' => 'Bật',
                'ja' => '有効',
            ],
            'admin.api_webhook.secret_once' => [
                'en' => 'Signing secret — copy it now, it is shown only once:',
                'vi' => 'Khoá ký — hãy sao chép ngay, khoá chỉ hiện một lần:',
                'ja' => '署名用の秘密鍵 — 今すぐコピーしてください。表示は一度だけです：',
            ],
            'admin.api_webhook.auto_disabled' => [
                'en' => 'Turned off after too many failed deliveries',
                'vi' => 'Đã tự tắt sau quá nhiều lần gửi lỗi',
                'ja' => '送信失敗が多すぎたため自動でオフになりました',
            ],
            'admin.api_webhook.deliveries' => [
                'en' => 'Last deliveries',
                'vi' => 'Các lần gửi gần nhất',
                'ja' => '最近の送信',
            ],
            'admin.api_webhook.regenerate_confirm' => [
                'en' => 'Create a new signing secret? The current one stops working at once.',
                'vi' => 'Tạo khoá ký mới? Khoá hiện tại hết hiệu lực ngay.',
                'ja' => '新しい秘密鍵を作成しますか？現在の鍵はすぐに使えなくなります。',
            ],
            'admin.api_webhook.url_refused' => [
                'en' => 'The URL must use https and point to a public address (not a local machine or internal network).',
                'vi' => 'URL phải dùng https và trỏ tới địa chỉ công khai (không phải máy nội bộ hay mạng nội bộ).',
                'ja' => 'URL は https で、公開アドレスを指している必要があります（ローカル・社内ネットワークは不可）。',
            ],
            'admin.api_webhook.delivery_pending' => [
                'en' => 'Waiting / retrying',
                'vi' => 'Đang chờ / thử lại',
                'ja' => '待機中・再試行中',
            ],
            'admin.api_webhook.delivery_delivered' => [
                'en' => 'Delivered',
                'vi' => 'Đã giao',
                'ja' => '送信済み',
            ],
            'admin.api_webhook.delivery_failed' => [
                'en' => 'Given up',
                'vi' => 'Đã bỏ',
                'ja' => '断念',
            ],
        ];
        $rows = [];
        foreach ($texts as $code => $byLocation) {
            foreach ($byLocation as $location => $text) {
                $rows[] = ['code' => $code, 'text' => $text, 'position' => 'admin.api_webhook', 'location' => $location];
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
        if ($schema->hasTable(GP247_DB_PREFIX . 'languages')) {
            // WHY insertOrIgnore: unique (code, location) keeps a translation the site already edited.
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(self::labels());
        }
    }

    /**
     * WARNING: drops the webhook tables — registrations, signing secrets and delivery history
     * are lost. Labels are left in place.
     *
     * @return void
     */
    public function down()
    {
        ApiSyncSchema::dropWebhooks(Schema::connection(GP247_DB_CONNECTION));
    }
};
