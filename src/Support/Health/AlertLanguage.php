<?php

namespace GP247\Core\Support\Health;

/**
 * Language rows (en / vi / ja) of the "System alerts" block, page and menu item — one
 * source for the install seeder and the upgrade migrations, so they never drift.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-admin-shell-system-alerts-page
 * @aidlc-adr admin-shell_system-alert-registry
 */
final class AlertLanguage
{
    /**
     * Texts keyed by language code, then locale.
     *
     * @return array<string, array{en: string, vi: string, ja: string}>
     */
    public static function texts(): array
    {
        return [
            'admin.alerts.title' => ['en' => 'System alerts', 'vi' => 'Cảnh báo hệ thống', 'ja' => 'システムアラート'],
            'admin.alerts.subtitle' => ['en' => 'Visible to administrators only', 'vi' => 'Chỉ quản trị viên cấp cao nhìn thấy', 'ja' => '管理者のみに表示されます'],
            'admin.alerts.checked_at' => ['en' => 'checked at :time', 'vi' => 'kiểm lúc :time', 'ja' => ':time に確認'],
            'admin.alerts.recheck' => ['en' => 'Check again', 'vi' => 'Kiểm tra lại', 'ja' => '再確認'],
            'admin.alerts.checking' => ['en' => 'Checking…', 'vi' => 'Đang kiểm tra…', 'ja' => '確認中…'],
            'admin.alerts.count_critical' => ['en' => ':count critical', 'vi' => ':count nghiêm trọng', 'ja' => '重大 :count 件'],
            'admin.alerts.count_warning' => ['en' => ':count to review', 'vi' => ':count cần lưu ý', 'ja' => '要確認 :count 件'],
            'admin.alerts.count_info' => ['en' => ':count info', 'vi' => ':count thông tin', 'ja' => '情報 :count 件'],
            'admin.alerts.passed_count' => ['en' => ':count other checks passed', 'vi' => ':count mục khác ổn', 'ja' => 'その他 :count 件は問題ありません'],
            'admin.alerts.all_ok' => ['en' => 'Nothing needs your attention', 'vi' => 'Không có gì cần xử lý', 'ja' => '対応が必要な項目はありません'],
            'admin.alerts.all_ok_detail' => ['en' => 'All :count checks passed', 'vi' => ':count mục kiểm tra đều ổn', 'ja' => ':count 件すべての確認に問題ありません'],
            'admin.alerts.summary_critical' => ['en' => 'Critical security issues: :count', 'vi' => 'Lỗi bảo mật nghiêm trọng: :count', 'ja' => '重大なセキュリティ問題: :count 件'],
            'admin.alerts.summary_review' => ['en' => 'Settings to review: :count — nothing critical', 'vi' => 'Cấu hình cần xem lại: :count — không có lỗi nghiêm trọng', 'ja' => '確認が必要な設定: :count 件 — 重大な問題はありません'],
            'admin.alerts.summary_ok' => ['en' => 'All :count security checks passed', 'vi' => 'Tất cả :count mục kiểm tra đều đạt', 'ja' => ':count 件のセキュリティ確認にすべて合格しました'],
            'admin.alerts.and_more' => ['en' => 'and :count more', 'vi' => 'và :count mục khác', 'ja' => 'ほか :count 件'],
            'admin.alerts.view_all' => ['en' => 'View all alerts', 'vi' => 'Xem tất cả cảnh báo', 'ja' => 'すべてのアラートを表示'],
            'admin.alerts.details' => ['en' => 'Details', 'vi' => 'Chi tiết', 'ja' => '詳細'],
            'admin.alerts.filter_all' => ['en' => 'All', 'vi' => 'Tất cả', 'ja' => 'すべて'],
            'admin.alerts.filter_critical' => ['en' => 'Critical', 'vi' => 'Nghiêm trọng', 'ja' => '重大'],
            'admin.alerts.filter_warning' => ['en' => 'To review', 'vi' => 'Cần lưu ý', 'ja' => '要確認'],
            'admin.alerts.filter_ok' => ['en' => 'Passed', 'vi' => 'Đạt', 'ja' => '合格'],
            'admin.alerts.group_info' => ['en' => 'For your information', 'vi' => 'Để bạn biết', 'ja' => '参考情報'],
            'admin.alerts.empty_filter' => ['en' => 'No check at this level.', 'vi' => 'Không có mục nào ở mức này.', 'ja' => 'このレベルの項目はありません。'],
            'admin.menu_titles.system_alerts' => ['en' => 'System alerts', 'vi' => 'Cảnh báo hệ thống', 'ja' => 'システムアラート'],
            'admin.alerts.doc' => ['en' => 'How to fix', 'vi' => 'Hướng dẫn', 'ja' => '対処方法'],
            'admin.alerts.fix_users' => ['en' => 'Open Users', 'vi' => 'Mở Người dùng', 'ja' => 'ユーザーを開く'],
            'admin.alerts.fix_password_policy' => ['en' => 'Open Password policy', 'vi' => 'Mở Chính sách mật khẩu', 'ja' => 'パスワードポリシーを開く'],
            'admin.alerts.fix_plugins' => ['en' => 'Open Plugins', 'vi' => 'Mở Plugin', 'ja' => 'プラグインを開く'],
            'admin.alerts.fix_api' => ['en' => 'Open API settings', 'vi' => 'Mở Cấu hình API', 'ja' => 'API 設定を開く'],
            'admin.alerts.app_debug' => [
                'en' => 'Debug mode is on — visitors can see file paths and configuration when a page fails.',
                'vi' => 'Debug đang bật — khách có thể thấy đường dẫn file và cấu hình khi trang lỗi.',
                'ja' => 'デバッグモードが有効です。ページでエラーが起きると、訪問者にファイルパスや設定が見えてしまいます。',
            ],
            'admin.alerts.app_debug_ok' => ['en' => 'Debug mode is off', 'vi' => 'Debug đã tắt', 'ja' => 'デバッグモードは無効です'],
            'admin.alerts.https' => [
                'en' => 'The site does not fully run on HTTPS (APP_URL or the secure session cookie).',
                'vi' => 'Site chưa chạy hoàn toàn trên HTTPS (APP_URL hoặc cookie phiên chưa bật secure).',
                'ja' => 'サイトが完全には HTTPS で動作していません（APP_URL またはセッション Cookie の secure 設定）。',
            ],
            'admin.alerts.https_ok' => ['en' => 'HTTPS and secure session cookie', 'vi' => 'HTTPS và cookie phiên secure', 'ja' => 'HTTPS とセキュアなセッション Cookie'],
            'admin.alerts.trusted_proxies' => [
                'en' => 'TRUSTED_PROXIES trusts every visitor — anyone can fake their IP address, getting around login limits and IP rules.',
                'vi' => 'TRUSTED_PROXIES đang tin mọi người truy cập — ai cũng giả được địa chỉ IP, vượt qua giới hạn đăng nhập và luật chặn IP.',
                'ja' => 'TRUSTED_PROXIES がすべての訪問者を信頼しています。誰でも IP アドレスを偽装でき、ログイン制限や IP ルールを回避できます。',
            ],
            'admin.alerts.trusted_proxies_ok' => ['en' => 'Only declared proxies are trusted', 'vi' => 'Chỉ tin proxy đã khai báo', 'ja' => '宣言済みのプロキシのみを信頼しています'],
            'admin.alerts.trusted_proxies_untrusted_hop' => [
                'en' => 'The admin pages come through a proxy missing from TRUSTED_PROXIES — links become http:// (the browser blocks admin actions as mixed content) and every visitor shows that proxy\'s IP.',
                'vi' => 'Trang quản trị đi qua một proxy chưa khai trong TRUSTED_PROXIES — link sinh ra là http:// (trình duyệt chặn thao tác quản trị vì mixed content) và mọi khách hiện cùng IP của proxy đó.',
                'ja' => '管理画面へのアクセスが TRUSTED_PROXIES に未登録のプロキシを経由しています。リンクが http:// になり（ブラウザが混在コンテンツとして管理操作をブロック）、すべての訪問者がそのプロキシの IP として記録されます。',
            ],
            'admin.alerts.default_admin_password' => [
                'en' => 'The account "admin" still signs in with the default password "admin".',
                'vi' => 'Tài khoản "admin" vẫn đăng nhập được bằng mật khẩu mặc định "admin".',
                'ja' => 'アカウント「admin」が初期パスワード「admin」のままログインできます。',
            ],
            'admin.alerts.default_admin_password_ok' => ['en' => 'No administrator uses the default password', 'vi' => 'Không còn tài khoản dùng mật khẩu mặc định', 'ja' => '初期パスワードのままの管理者はいません'],
            'admin.alerts.password_policy' => [
                'en' => 'Admin passwords only need :min characters — 12 or more is recommended.',
                'vi' => 'Mật khẩu quản trị chỉ cần tối thiểu :min ký tự — nên đặt từ 12.',
                'ja' => '管理者パスワードの最小文字数が :min 文字です。12 文字以上を推奨します。',
            ],
            'admin.alerts.password_policy_ok' => ['en' => 'Password policy of 12+ characters', 'vi' => 'Chính sách mật khẩu từ 12 ký tự', 'ja' => 'パスワードポリシーは 12 文字以上'],
            'admin.alerts.encryption_key' => [
                'en' => 'No dedicated encryption key (GP247_ENCRYPTION_KEY) — changing APP_KEY would lose saved SMTP passwords and licenses.',
                'vi' => 'Chưa đặt khoá mã hoá riêng (GP247_ENCRYPTION_KEY) — lỡ đổi APP_KEY là mất mật khẩu SMTP và license đã lưu.',
                'ja' => '専用の暗号化キー（GP247_ENCRYPTION_KEY）がありません。APP_KEY を変更すると保存済みの SMTP パスワードやライセンスが失われます。',
            ],
            'admin.alerts.encryption_key_ok' => ['en' => 'A dedicated encryption key is set', 'vi' => 'Đã có khoá mã hoá riêng', 'ja' => '専用の暗号化キーが設定されています'],
            'admin.alerts.secret_decryptable' => [
                'en' => 'Saved secrets that no longer decrypt: :count — mail, payment or sign-in features using them have stopped.',
                'vi' => ':count bí mật đã lưu không còn giải mã được — gửi mail, thanh toán hoặc đăng nhập dùng chúng đang ngừng.',
                'ja' => '保存済みのシークレット :count 件を復号できません。それを使うメール送信・決済・ログインが停止しています。',
            ],
            'admin.alerts.secret_decryptable_ok' => ['en' => 'Saved secrets decrypt', 'vi' => 'Bí mật cấu hình giải mã được', 'ja' => '保存済みのシークレットは復号できます'],
            'admin.alerts.mfa' => [
                'en' => 'Two-factor sign-in is not enabled — a leaked password is enough to enter the admin area.',
                'vi' => 'Chưa bật xác thực hai lớp — lộ mật khẩu là vào được trang quản trị.',
                'ja' => '二要素認証が有効になっていません。パスワードが漏れるだけで管理画面に入れてしまいます。',
            ],
            'admin.alerts.mfa_ok' => ['en' => 'Two-factor sign-in is enabled', 'vi' => 'Xác thực hai lớp đang bật', 'ja' => '二要素認証は有効です'],
            'admin.alerts.api_exposure' => [
                'en' => 'The REST API is on (MCP too when installed) — keep it on only if you use it.',
                'vi' => 'REST API đang bật (gồm cả MCP nếu có cài) — chỉ nên bật khi đang dùng.',
                'ja' => 'REST API が有効です（インストール済みなら MCP も）。使う場合のみ有効にしてください。',
            ],
            'admin.alerts.api_exposure_ok' => ['en' => 'REST API and MCP are off', 'vi' => 'REST API và MCP đang tắt', 'ja' => 'REST API と MCP は無効です'],
            'admin.alerts.support_line_soon' => [
                'en' => 'This version line receives security fixes only until :month.',
                'vi' => 'Dòng phiên bản đang dùng chỉ còn được vá bảo mật tới :month.',
                'ja' => 'このバージョン系列のセキュリティ修正は :month までです。',
            ],
            'admin.alerts.support_line_ended' => [
                'en' => 'This version line stopped receiving security fixes after :month — upgrade.',
                'vi' => 'Dòng phiên bản đang dùng đã hết được vá bảo mật sau :month — hãy nâng cấp.',
                'ja' => 'このバージョン系列は :month でセキュリティ修正が終了しました。アップグレードしてください。',
            ],
            'admin.alerts.support_line_ok' => ['en' => 'Version line supported (until :month)', 'vi' => 'Phiên bản còn được hỗ trợ (tới :month)', 'ja' => 'サポート対象のバージョン（:month まで）'],
            'admin.alerts.support_line_unknown' => ['en' => 'Support period not configured', 'vi' => 'Chưa khai thời hạn hỗ trợ', 'ja' => 'サポート期間が設定されていません'],
            'admin.alerts.extension_data_pending' => [
                'en' => 'Extensions updated on disk whose data update has not run: :count.',
                'vi' => ':count extension đã cập nhật mã nguồn nhưng chưa chạy cập nhật dữ liệu.',
                'ja' => ':count 件の拡張機能はファイルが更新されましたが、データ更新が実行されていません。',
            ],
            'admin.alerts.extension_data_pending_ok' => ['en' => 'No extension waits for a data update', 'vi' => 'Extension không chờ cập nhật dữ liệu', 'ja' => 'データ更新待ちの拡張機能はありません'],
            'admin.alerts.extension_assets' => [
                'en' => 'Extensions missing static files in public/: :count — run gp247:ext-publish.',
                'vi' => ':count extension thiếu file tĩnh trong public/ — chạy gp247:ext-publish.',
                'ja' => ':count 件の拡張機能で public/ の静的ファイルが不足しています。gp247:ext-publish を実行してください。',
            ],
            'admin.alerts.extension_assets_ok' => ['en' => 'Extensions have their static files', 'vi' => 'Extension đủ file tĩnh', 'ja' => '拡張機能の静的ファイルは揃っています'],
            'admin.alerts.raw_output_scan' => [
                'en' => 'Site templates/plugins print :count field(s) raw with {!! !!} — text is now stored as typed, so switch them to {{ }}.',
                'vi' => 'Template/plugin của site in thô :count chỗ bằng {!! !!} — chữ nay được lưu đúng như nhập, hãy đổi sang {{ }}.',
                'ja' => 'サイトのテンプレート/プラグインが :count 箇所を {!! !!} でそのまま出力しています。文字は入力どおりに保存されるようになったため {{ }} に変更してください。',
            ],
            'admin.alerts.raw_output_scan_ok' => ['en' => 'Site templates print text safely', 'vi' => 'Template của site in chữ an toàn', 'ja' => 'サイトのテンプレートは文字を安全に出力しています'],
        ];
    }

    /**
     * Rows for the `languages` table.
     *
     * @return array<int, array{code: string, text: string, position: string, location: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::texts() as $code => $byLocale) {
            foreach ($byLocale as $location => $text) {
                // Position = code without its last segment ("admin.alerts", "admin.menu_titles").
                $rows[] = ['code' => $code, 'text' => $text, 'position' => substr($code, 0, (int) strrpos($code, '.')), 'location' => $location];
            }
        }

        return $rows;
    }
}
