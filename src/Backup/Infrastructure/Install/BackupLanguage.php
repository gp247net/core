<?php

namespace GP247\Core\Backup\Infrastructure\Install;

/**
 * Language rows (en / vi / ja) of the backup area installed with it — one source for the
 * install seeder and the upgrade migrations. Screen texts: BackupScreenLanguage (BK-4).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-install-lifecycle
 * @aidlc-adr backup-engine_core-module-layout
 */
final class BackupLanguage
{
    /**
     * @return array<string, array{en: string, vi: string, ja: string}>
     */
    public static function texts(): array
    {
        $screen = [];
        foreach (BackupScreenLanguage::rows() as $code => [$en, $vi, $ja]) {
            $screen[$code] = ['en' => $en, 'vi' => $vi, 'ja' => $ja];
        }

        return $screen + [
            'admin.menu_titles.backup' => [
                'en' => 'Backup & restore',
                'vi' => 'Sao lưu & phục hồi',
                'ja' => 'バックアップと復元',
            ],
            'admin.backup.enabled' => [
                'en' => 'Backup feature',
                'vi' => 'Chức năng sao lưu',
                'ja' => 'バックアップ機能',
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
                $rows[] = ['code' => $code, 'text' => $text, 'position' => substr($code, 0, strrpos($code, '.')), 'location' => $location];
            }
        }

        return $rows;
    }
}
