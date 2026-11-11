<?php

namespace GP247\Core\Backup\Infrastructure\Install;

/**
 * Texts added by the re-assessment of the backup area (mod 20261110T164834), en / vi / ja: what a
 * restore does not bring back, columns whose data would be lost, the newest full backup, a key
 * ring that cannot be trusted, and the new refusals. Merged into BackupScreenLanguage::rows().
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-version-compatibility, US-backup-engine-retention, US-backup-engine-key-setup, US-backup-admin-step-up-auth
 */
final class BackupReassessmentLanguage
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rows(): array
    {
        return [
            'admin.backup.restore.not_restored' => ['Not brought back by this restore', 'Lần phục hồi này KHÔNG đưa lại', 'この復元では戻らないもの'],
            'admin.backup.restore.not_restored_absent' => ['Tables in the backup that this site does not have (extension not installed)', 'Bảng có trong bản nhưng site không có (extension chưa cài)', 'バックアップにあるがこのサイトにないテーブル（拡張機能が未インストール）'],
            'admin.backup.restore.not_restored_excluded' => ['Tables never replaced by a restore (sign-in tokens, activity logs, backup bookkeeping…)', 'Bảng không bao giờ bị thay khi phục hồi (token đăng nhập, nhật ký thao tác, sổ của khu sao lưu…)', '復元で置き換えないテーブル（ログイントークン、操作ログ、バックアップの管理情報など）'],
            'admin.backup.restore.not_restored_not_in_archive' => ['Tables of the chosen groups that the backup does not hold', 'Bảng thuộc nhóm đã chọn nhưng bản không có', '選んだグループのうちバックアップにないテーブル'],
            'admin.backup.restore.not_restored_files' => ['Files in the backup', 'File có trong bản', 'バックアップ内のファイル'],
            'admin.backup.restore.files_cli_only' => ['Files (images, uploads, code, .env) are written back only with the command "php artisan gp247:backup-restore" on the server.', 'File (ảnh, file tải lên, mã, .env) chỉ ghi lại được bằng lệnh "php artisan gp247:backup-restore" trên máy chủ.', 'ファイル（画像、アップロード、コード、.env）はサーバー上のコマンド「php artisan gp247:backup-restore」でのみ書き戻せます。'],
            'admin.backup.restore.lost_columns' => ['These columns are in the backup but no longer on the site (an update removed or renamed them): their data will NOT come back.', 'Các cột này có trong bản nhưng site không còn (bản cập nhật đã xoá / đổi tên): dữ liệu của chúng sẽ KHÔNG về.', 'これらの列はバックアップにありますが、サイトにはもうありません（更新で削除・名前変更されました）。そのデータは戻りません。'],
            'admin.backup.restore.accept_column_loss' => ['I understand that the data of these columns is lost', 'Tôi hiểu dữ liệu của các cột này sẽ mất', 'これらの列のデータが失われることを理解しました'],
            'admin.backup.refused.columns_lost' => ['Columns of this backup no longer exist on the site: confirm that their data is lost, then preview again.', 'Bản có cột mà site không còn: hãy xác nhận dữ liệu của chúng sẽ mất rồi xem trước lại.', 'このバックアップの一部の列はサイトにもうありません。そのデータが失われることを確認してから、もう一度プレビューしてください。'],
            'admin.backup.refused.unverified_admin_only' => ['A backup this site did not sign can be restored only by an Administrator.', 'Bản không do site này ký chỉ Administrator được phục hồi.', 'このサイトが署名していないバックアップは管理者のみが復元できます。'],
            'admin.backup.refused.secret_throttled' => ['Too many wrong backup passwords. Wait a while before trying again.', 'Nhập sai mật khẩu sao lưu quá nhiều lần. Hãy chờ một lúc rồi thử lại.', 'バックアップのパスワードの誤りが多すぎます。しばらく待ってから再試行してください。'],
            'admin.backup.refused.key_ring_untrusted' => ['The backup keys cannot be trusted (stored unencrypted). Ask your developer to reset them; older backups can still be restored with the backup password.', 'Khoá sao lưu không đáng tin (lưu không mã hoá). Hãy nhờ lập trình viên đặt lại khoá; các bản cũ vẫn phục hồi được bằng mật khẩu sao lưu.', 'バックアップキーを信頼できません（暗号化されずに保存）。開発者にリセットを依頼してください。以前のバックアップはパスワードで引き続き復元できます。'],
            'admin.backup.restore.missing_by_source' => ['Updates the backup has that this site is missing, by package / extension (update the site or install the extension first):', 'Các bản cập nhật bản sao lưu có mà site còn thiếu, theo package / extension (hãy cập nhật site hoặc cài extension trước):', 'バックアップにあってこのサイトにない更新（パッケージ／拡張機能ごと。先にサイトを更新するか拡張機能をインストールしてください）：'],
            'admin.backup.restore.missing_unknown' => ['not found on this site (an extension that is not installed?)', 'không tìm thấy trên site (extension chưa cài?)', 'このサイトに見つかりません（未インストールの拡張機能？）'],
            'admin.backup.status.full_age' => ['Newest verified full backup: :hours hours ago', 'Bản toàn bộ đã kiểm gần nhất: :hours giờ trước', '最新の検証済み全体バックアップ：:hours 時間前'],
            'admin.backup.warning.no_full' => ['There is no verified FULL backup: the newest backups are snapshots of some groups only. A whole-database restore needs a full backup.', 'Chưa có bản sao lưu TOÀN BỘ nào đã kiểm: các bản mới chỉ là bản chụp vài nhóm. Phục hồi toàn DB cần bản toàn bộ.', '検証済みの「全体」バックアップがありません。最近のバックアップは一部のグループのスナップショットのみです。データベース全体の復元には全体バックアップが必要です。'],
            'admin.backup.warning.full_stale' => ['The newest verified full backup is older than :hours hours.', 'Bản toàn bộ đã kiểm gần nhất đã cũ hơn :hours giờ.', '最新の検証済み全体バックアップは :hours 時間以上前のものです。'],
            'admin.backup.warning.key_ring_untrusted' => ['The backup signing key is stored unencrypted: someone with database access may have replaced the backup keys. No backup is made until your developer resets the backup keys.', 'Khoá ký sao lưu đang lưu không mã hoá: có thể ai đó có quyền vào DB đã thay khoá sao lưu. Không bản nào được tạo cho tới khi lập trình viên đặt lại khoá sao lưu.', 'バックアップの署名キーが暗号化されずに保存されています。データベースにアクセスできる誰かがキーを置き換えた可能性があります。開発者がキーをリセットするまでバックアップは作成されません。'],
        ];
    }
}
