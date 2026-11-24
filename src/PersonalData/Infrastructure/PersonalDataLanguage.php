<?php

namespace GP247\Core\PersonalData\Infrastructure;

/**
 * Language rows (en / vi / ja) of the shared "confirm it is you" box and of the personal data
 * feature in the core — read by the core language seeder, which `gp247:update` re-runs with
 * insertOrIgnore.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete, US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_shared-step-up
 */
final class PersonalDataLanguage
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}> Code => [en, vi, ja].
     */
    public static function texts(): array
    {
        return [
            'admin.step_up.title' => ['Confirm it is you', 'Xác nhận lại danh tính', '本人確認'],
            'admin.step_up.help' => [
                'Type the password you use to sign in to the admin area. This action cannot be undone, so it asks who you are. It stays valid :minutes minutes in this browser.',
                'Nhập mật khẩu bạn dùng để đăng nhập trang quản trị. Thao tác này không hoàn tác được nên cần xác nhận người thực hiện. Hiệu lực :minutes phút trên trình duyệt này.',
                '管理画面へのログインに使うパスワードを入力してください。この操作は元に戻せないため、本人確認が必要です。このブラウザーで :minutes 分間有効です。',
            ],
            'admin.step_up.password' => ['Admin sign-in password', 'Mật khẩu đăng nhập trang quản trị', '管理画面のログインパスワード'],
            'admin.step_up.code' => ['Two-step code (or a recovery code)', 'Mã xác thực hai bước (hoặc mã khôi phục)', '2 段階認証コード（またはリカバリーコード）'],
            'admin.step_up.confirm' => ['Confirm', 'Xác nhận', '確認'],
            'admin.step_up.cancel' => ['Cancel', 'Huỷ', 'キャンセル'],
            'admin.step_up.step_up_required' => ['Confirm it is you first.', 'Hãy xác nhận lại danh tính trước.', '先に本人確認をしてください。'],
            'admin.step_up.wrong_password' => ['The password is not correct.', 'Mật khẩu không đúng.', 'パスワードが正しくありません。'],
            'admin.step_up.wrong_code' => ['The code is not correct.', 'Mã không đúng.', 'コードが正しくありません。'],
            'admin.step_up.throttled' => ['Too many tries. Wait :seconds seconds.', 'Thử sai quá nhiều lần. Chờ :seconds giây.', '試行回数が多すぎます。:seconds 秒お待ちください。'],
            'admin.step_up.no_admin' => ['Sign in again.', 'Hãy đăng nhập lại.', '再度ログインしてください。'],
            'admin.personal_data.deleted_marker' => ['Deleted customer', 'Khách đã xoá', '削除済みの顧客'],
            'admin.personal_data.status.running' => ['Erasing… :done of :total steps', 'Đang xoá… :done / :total bước', '削除中… :done / :total'],
            'admin.personal_data.status.done' => ['The customer and their personal data were erased.', 'Đã xoá khách hàng và dữ liệu cá nhân của khách.', '顧客と個人データを削除しました。'],
            'admin.personal_data.status.failed' => ['The erasure stopped on an error. It will be retried; if it keeps failing, check the system log.', 'Việc xoá dừng vì lỗi. Hệ thống sẽ thử lại; nếu vẫn lỗi, hãy xem nhật ký hệ thống.', 'エラーで削除が止まりました。再試行されます。失敗が続く場合はシステムログを確認してください。'],
            'admin.personal_data.source.login_failures' => ['Failed sign-ins', 'Lần đăng nhập sai', 'ログイン失敗'],
            'admin.personal_data.not_ready' => ['Run php artisan gp247:update first.', 'Hãy chạy php artisan gp247:update trước.', '先に php artisan gp247:update を実行してください。'],
        ];
    }

    /**
     * @return list<array{code: string, text: string, position: string, location: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::texts() as $code => $texts) {
            foreach (['en', 'vi', 'ja'] as $i => $location) {
                $rows[] = [
                    'code' => $code,
                    'text' => $texts[$i],
                    'position' => substr($code, 0, (int) strrpos($code, '.')),
                    'location' => $location,
                ];
            }
        }

        return $rows;
    }
}
