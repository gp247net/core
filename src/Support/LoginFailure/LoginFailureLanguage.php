<?php

namespace GP247\Core\Support\LoginFailure;

/**
 * Language rows (en / vi / ja) of the failed sign-in log, its screen and its alert —
 * one source for the install seeder and the upgrade migration.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-list, US-admin-shell-login-failure-alert
 * @aidlc-adr admin-shell_login-failure-log
 */
final class LoginFailureLanguage
{
    /**
     * Texts keyed by language code, then locale.
     *
     * @return array<string, array{en: string, vi: string, ja: string}>
     */
    public static function texts(): array
    {
        return [
            'admin.menu_titles.login_failures' => ['en' => 'Login failures', 'vi' => 'Đăng nhập sai', 'ja' => 'ログイン失敗'],
            'admin.login_failures.title' => ['en' => 'Login failures', 'vi' => 'Lần đăng nhập sai', 'ja' => 'ログイン失敗'],
            'admin.login_failures.subtitle' => [
                'en' => 'Visible to administrators only. Rows are kept :days days.',
                'vi' => 'Chỉ quản trị viên cấp cao nhìn thấy. Lưu :days ngày.',
                'ja' => '管理者のみに表示されます。:days 日間保存されます。',
            ],
            'admin.login_failures.audiences' => ['en' => 'Record failed sign-ins of', 'vi' => 'Ghi lần đăng nhập sai của', 'ja' => 'ログイン失敗を記録する対象'],
            'admin.login_failures.save' => ['en' => 'Save', 'vi' => 'Lưu', 'ja' => '保存'],
            'admin.login_failures.saved' => ['en' => 'Saved', 'vi' => 'Đã lưu', 'ja' => '保存しました'],
            'admin.login_failures.group' => ['en' => 'Group', 'vi' => 'Nhóm', 'ja' => 'グループ'],
            'admin.login_failures.channel' => ['en' => 'Channel', 'vi' => 'Kênh', 'ja' => 'チャネル'],
            'admin.login_failures.identity' => ['en' => 'Username / e-mail typed', 'vi' => 'Tên đăng nhập / email đã gõ', 'ja' => '入力されたユーザー名 / メール'],
            'admin.login_failures.reason' => ['en' => 'Reason', 'vi' => 'Lý do', 'ja' => '理由'],
            'admin.login_failures.reason_invalid_credentials' => ['en' => 'Wrong credentials', 'vi' => 'Sai thông tin đăng nhập', 'ja' => '認証情報の誤り'],
            'admin.login_failures.reason_throttled' => ['en' => 'Blocked: too many tries', 'vi' => 'Bị chặn: thử quá nhiều', 'ja' => '試行回数超過でブロック'],
            'admin.login_failures.account_exists' => ['en' => 'existing account', 'vi' => 'tài khoản có thật', 'ja' => '既存のアカウント'],
            'admin.login_failures.ip' => ['en' => 'IP', 'vi' => 'IP', 'ja' => 'IP'],
            'admin.login_failures.user_agent' => ['en' => 'Browser / app', 'vi' => 'Trình duyệt / ứng dụng', 'ja' => 'ブラウザ / アプリ'],
            'admin.login_failures.time' => ['en' => 'Time', 'vi' => 'Thời điểm', 'ja' => '日時'],
            'admin.login_failures.all_groups' => ['en' => 'All groups', 'vi' => 'Mọi nhóm', 'ja' => 'すべてのグループ'],
            'admin.login_failures.from' => ['en' => 'From', 'vi' => 'Từ ngày', 'ja' => '開始日'],
            'admin.login_failures.to' => ['en' => 'To', 'vi' => 'Đến ngày', 'ja' => '終了日'],
            'admin.login_failures.empty' => ['en' => 'No failed sign-in recorded.', 'vi' => 'Chưa có lần đăng nhập sai nào được ghi.', 'ja' => '記録されたログイン失敗はありません。'],
            'admin.alerts.login_failures_ok' => [
                'en' => 'No sign of password guessing in the last 24 hours',
                'vi' => 'Không có dấu hiệu dò mật khẩu trong 24 giờ qua',
                'ja' => '過去 24 時間にパスワード推測の兆候はありません',
            ],
            'admin.alerts.login_failures_warning' => [
                'en' => ':count failed or blocked administrator sign-ins in the last 24 hours — someone may be guessing passwords.',
                'vi' => ':count lần đăng nhập quản trị sai hoặc bị chặn trong 24 giờ qua — có thể đang bị dò mật khẩu.',
                'ja' => '過去 24 時間に管理者ログインの失敗またはブロックが :count 件あります。パスワードが推測されている可能性があります。',
            ],
            'admin.alerts.login_failures_critical' => [
                'en' => 'Administrator passwords are under attack: :count failed sign-ins from :ips addresses in the last 24 hours.',
                'vi' => 'Mật khẩu quản trị đang bị dò: :count lần đăng nhập sai từ :ips địa chỉ trong 24 giờ qua.',
                'ja' => '管理者パスワードが攻撃されています：過去 24 時間に :ips 件のアドレスから :count 件のログイン失敗。',
            ],
            'admin.alerts.login_failures_info' => [
                'en' => ':count failed sign-ins of other accounts (customers…) in the last 24 hours.',
                'vi' => ':count lần đăng nhập sai của tài khoản khác (khách hàng…) trong 24 giờ qua.',
                'ja' => '過去 24 時間にほかのアカウント（顧客など）のログイン失敗が :count 件あります。',
            ],
            'admin.alerts.login_failures_not_recorded' => [
                'en' => 'Failed administrator sign-ins are not recorded — password guessing would go unnoticed.',
                'vi' => 'Lần đăng nhập quản trị sai không được ghi — bị dò mật khẩu cũng không ai biết.',
                'ja' => '管理者のログイン失敗が記録されていません。パスワード推測に気付けません。',
            ],
            'admin.alerts.login_failures_write_error' => [
                'en' => 'Failed sign-ins could not be recorded in the last 24 hours — password guessing may have gone unnoticed. Check the application log.',
                'vi' => 'Có lần đăng nhập sai không ghi được trong 24 giờ qua — có thể bị dò mật khẩu mà không thấy. Xem log ứng dụng.',
                'ja' => '過去 24 時間にログイン失敗を記録できないことがありました。パスワード推測を見逃している可能性があります。アプリケーションログを確認してください。',
            ],
            'admin.alerts.fix_login_failures' => ['en' => 'Open Login failures', 'vi' => 'Mở Đăng nhập sai', 'ja' => 'ログイン失敗を開く'],
        ];
    }

    /**
     * Rows for the `languages` table; position = code without its last segment.
     *
     * @return list<array{code: string, text: string, position: string, location: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::texts() as $code => $byLocale) {
            foreach ($byLocale as $location => $text) {
                $rows[] = ['code' => $code, 'text' => $text, 'position' => substr($code, 0, (int) strrpos($code, '.')), 'location' => $location];
            }
        }

        return $rows;
    }
}
