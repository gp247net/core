<?php

namespace GP247\Core\Support\PreChange;

/**
 * Language rows (en / vi / ja) of the "before change" seam — one source for the install
 * seeder and the upgrade migration, so they never drift.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-pre-change-hook, US-PLG-pre-change-hook
 * @aidlc-adr system-cli_pre-change-registry
 */
final class PreChangeLanguage
{
    /**
     * Texts keyed by language code, then locale.
     *
     * @return array<string, array{en: string, vi: string, ja: string}>
     */
    public static function texts(): array
    {
        return [
            'admin.pre_change.registrant_failed' => [
                'en' => 'The check before this change (:key) failed, so nothing was changed.',
                'vi' => 'Bước kiểm tra trước thay đổi (:key) bị lỗi nên chưa có gì bị thay đổi.',
                'ja' => '変更前のチェック（:key）が失敗したため、何も変更されていません。',
            ],
            'admin.pre_change.invalid_result' => [
                'en' => 'The check before this change (:key) gave no valid answer, so nothing was changed.',
                'vi' => 'Bước kiểm tra trước thay đổi (:key) không trả lời hợp lệ nên chưa có gì bị thay đổi.',
                'ja' => '変更前のチェック（:key）から有効な応答がなかったため、何も変更されていません。',
            ],
            'admin.pre_change.update_continue' => [
                'en' => 'No snapshot was taken before this update: :reason Update anyway without a snapshot?',
                'vi' => 'Chưa chụp được snapshot trước khi cập nhật: :reason Vẫn cập nhật mà không có snapshot?',
                'ja' => '更新前のスナップショットを作成できませんでした：:reason スナップショットなしで更新しますか？',
            ],
            'admin.pre_change.template_blocked' => [
                'en' => 'No snapshot was taken before switching template:',
                'vi' => 'Chưa chụp được snapshot trước khi đổi template:',
                'ja' => 'テンプレート切り替え前のスナップショットを作成できませんでした：',
            ],
            'admin.pre_change.continue_without_snapshot' => [
                'en' => 'Continue without a snapshot',
                'vi' => 'Tiếp tục không có snapshot',
                'ja' => 'スナップショットなしで続行',
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
                $rows[] = ['code' => $code, 'text' => $text, 'position' => 'admin.pre_change', 'location' => $location];
            }
        }

        return $rows;
    }
}
