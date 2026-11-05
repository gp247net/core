<?php

namespace GP247\Core\Support\Password;

/**
 * Language rows (en / vi / ja) of the Password policy screen — one source for the install
 * seeder and the upgrade migration, so they never drift.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-policy-audiences
 * @aidlc-adr admin-shell_password-policy-registry
 */
final class PasswordPolicyLanguage
{
    /**
     * Texts keyed by language code, then locale.
     *
     * @return array<string, array{en: string, vi: string, ja: string}>
     */
    public static function texts(): array
    {
        return [
            'admin.password_policy.audience_admin' => [
                'en' => 'Administrators',
                'vi' => 'Quản trị viên',
                'ja' => '管理者',
            ],
            'admin.password_policy.error_min' => [
                'en' => 'The minimum must be a whole number of at least :floor.',
                'vi' => 'Số ký tự tối thiểu phải là số nguyên từ :floor trở lên.',
                'ja' => '最小文字数は :floor 以上の整数にしてください。',
            ],
            'admin.password_policy.error_max' => [
                'en' => 'The maximum must be a whole number of at most :ceiling.',
                'vi' => 'Số ký tự tối đa phải là số nguyên không quá :ceiling.',
                'ja' => '最大文字数は :ceiling 以下の整数にしてください。',
            ],
            'admin.password_policy.error_bytes' => [
                'en' => 'The password is too long: it must fit in :ceiling bytes (fewer characters when it has accents or non-Latin letters).',
                'vi' => 'Mật khẩu quá dài: phải vừa :ceiling byte (ít ký tự hơn khi có dấu hoặc chữ không phải Latin).',
                'ja' => 'パスワードが長すぎます：:ceiling バイト以内に収めてください（アクセント付きの文字や日本語などを含む場合は文字数が少なくなります）。',
            ],
            'admin.password_policy.error_range' => [
                'en' => 'The minimum cannot be greater than the maximum.',
                'vi' => 'Số ký tự tối thiểu không được lớn hơn số ký tự tối đa.',
                'ja' => '最小文字数は最大文字数以下にしてください。',
            ],
        ];
    }

    /**
     * Rows for the `languages` table.
     *
     * @return list<array{code: string, text: string, position: string, location: string}>
     */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::texts() as $code => $byLocale) {
            foreach ($byLocale as $location => $text) {
                $rows[] = ['code' => $code, 'text' => $text, 'position' => 'admin.password_policy', 'location' => $location];
            }
        }

        return $rows;
    }
}
