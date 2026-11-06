<?php

namespace GP247\Core\Backup\Infrastructure\Install;

/**
 * Texts of the recovery tools (Bolt BK-5): upload / import, unverified origin, post-incident mode,
 * the "choose a new password" sign-in step and the new refusal reasons — en / vi / ja. Merged into
 * BackupScreenLanguage::rows() (one source for the seeder and the upgrade migration).
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive, US-backup-restore-post-incident-mode, US-backup-restore-cli-restore
 */
final class BackupRecoveryLanguage
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rows(): array
    {
        return self::import() + self::postIncident() + self::refusals();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function import(): array
    {
        return [
            'admin.backup.import.title' => ['Bring in a backup file', 'Đưa bản sao lưu vào', 'バックアップファイルの取り込み'],
            'admin.backup.import.subtitle' => ['A .gp247bak file downloaded earlier or made on another server (up to :max MB).', 'File .gp247bak đã tải về trước đó hoặc tạo trên máy chủ khác (tối đa :max MB).', '以前ダウンロードした、または別のサーバーで作成した .gp247bak ファイル（最大 :max MB）。'],
            'admin.backup.import.help' => ['Type the backup password or recovery key of the site that made the file in the field above, then choose the file. It is sent in small pieces and checked in full before it appears in the list.', 'Nhập mật khẩu sao lưu hoặc recovery key của site đã tạo file vào ô phía trên, rồi chọn file. File được gửi thành từng phần nhỏ và kiểm đầy đủ trước khi hiện trong danh sách.', '上の欄に、ファイルを作成したサイトのバックアップ用パスワードまたはリカバリーキーを入力してからファイルを選択してください。小さく分けて送信され、一覧に表示される前にすべて検証されます。'],
            'admin.backup.import.start' => ['Upload and check', 'Tải lên và kiểm', 'アップロードして検証'],
            'admin.backup.import.upload_failed' => ['The upload did not finish', 'Tải lên không hoàn tất', 'アップロードが完了しませんでした'],
            'admin.backup.import.started' => ['The file is being checked. It appears in the list when the check passes.', 'File đang được kiểm. Kiểm đạt thì file hiện trong danh sách.', 'ファイルを検証中です。合格すると一覧に表示されます。'],
            'admin.backup.import.last_done' => ['Last file brought in: backup :archive.', 'File đưa vào gần nhất: bản :archive.', '最後に取り込んだファイル: バックアップ :archive。'],
            'admin.backup.import.last_failed' => ['The last file was not brought in (:reason); it was removed.', 'File gần nhất không đưa vào được (:reason); file đã bị xoá.', '最後のファイルは取り込めませんでした（:reason）。ファイルは削除されました。'],
            'admin.backup.origin.unverified' => ['This backup was not signed by this site (made on another server, or the signing key of this site is lost): its data is intact but who made it cannot be proven. Restore it only if you know where it comes from. Code and .env are never restored from it.', 'Bản này không được ký bởi site này (tạo trên máy chủ khác, hoặc site đã mất khoá ký): dữ liệu nguyên vẹn nhưng không chứng minh được ai tạo. Chỉ phục hồi khi bạn biết rõ nguồn gốc. Không bao giờ phục hồi mã và .env từ bản này.', 'このバックアップはこのサイトの署名がありません（別のサーバーで作成、またはこのサイトの署名鍵が失われた）。データは完全ですが、作成者は証明できません。出所が確かな場合のみ復元してください。コードと .env はこのバックアップから復元されません。'],
            'admin.backup.origin.unverified_short' => ['origin not verified', 'chưa xác minh nguồn gốc', '出所未確認'],
            'admin.backup.origin.accept' => ['I know where this backup comes from — restore it anyway', 'Tôi biết rõ nguồn gốc bản này — vẫn phục hồi', 'このバックアップの出所を把握しています — 復元する'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function postIncident(): array
    {
        return [
            'admin.password_change_required' => ['For security, choose a new password before continuing.', 'Vì lý do bảo mật, hãy đặt mật khẩu mới trước khi tiếp tục.', 'セキュリティのため、続行する前に新しいパスワードを設定してください。'],
            'admin.backup.post_incident.option' => ['Restore after a security incident', 'Phục hồi sau sự cố bảo mật', 'セキュリティインシデント後の復元'],
            'admin.backup.post_incident.option_help' => ['Choose a backup made BEFORE the suspected intrusion. Afterwards every session ends (yours too), every API / MCP token is revoked (integrations need new tokens) and every admin must choose a new password.', 'Hãy chọn bản tạo TRƯỚC thời điểm nghi bị xâm nhập. Sau khi phục hồi: mọi phiên đăng nhập kết thúc (kể cả của bạn), mọi token API / MCP bị thu hồi (tích hợp phải cấp lại token), mọi admin phải đặt mật khẩu mới.', '侵入が疑われる時点より前のバックアップを選んでください。復元後、すべてのセッションが終了し（あなたも含む）、すべての API / MCP トークンが無効になり（連携には新しいトークンが必要）、すべての管理者が新しいパスワードを設定する必要があります。'],
            'admin.backup.post_incident.preview' => ['Post-incident mode: sessions end, API / MCP tokens are revoked and new admin passwords are required right after the restore. You will be signed out.', 'Chế độ sau sự cố: ngay sau khi phục hồi, các phiên kết thúc, token API / MCP bị thu hồi và admin phải đặt mật khẩu mới. Bạn sẽ bị đăng xuất.', 'インシデント後モード: 復元直後にセッションが終了し、API / MCP トークンが無効になり、管理者は新しいパスワードが必要になります。あなたもサインアウトされます。'],
            'admin.backup.post_incident.pending_title' => ['After the post-incident restore, these tasks are still to be done by hand:', 'Sau lần phục hồi sau sự cố, còn các việc phải tự làm:', 'インシデント後の復元の後、次の作業を手動で行ってください:'],
            'admin.backup.post_incident.task_rotate_key' => ['Rotate the site encryption key: put a new :key in .env, move the old value to :previous, run ":command", and remove the old key only after the command finished.', 'Xoay khoá mã hoá của site: đặt :key mới trong .env, chuyển giá trị cũ sang :previous, chạy ":command", chỉ bỏ khoá cũ sau khi lệnh chạy xong.', 'サイトの暗号鍵をローテーションします: .env に新しい :key を設定し、古い値を :previous に移して「:command」を実行し、完了後にのみ古い鍵を削除してください。'],
            'admin.backup.post_incident.task_reinstall_code' => ['Reinstall the code (core, plugins, templates) from its original source.', 'Cài lại mã (core, plugin, template) từ nguồn gốc.', 'コード（core、プラグイン、テンプレート）を元の配布元から再インストールしてください。'],
            'admin.backup.post_incident.task_change_hosting_passwords' => ['Change the database, hosting, FTP and e-mail passwords.', 'Đổi mật khẩu database, hosting, FTP và e-mail.', 'データベース、ホスティング、FTP、メールのパスワードを変更してください。'],
            'admin.backup.post_incident.task_run_doctor' => ['Run ":doctor" and fix what it reports.', 'Chạy ":doctor" và xử lý những gì nó báo.', '「:doctor」を実行し、報告された問題を修正してください。'],
            'admin.backup.post_incident.mark_done' => ['These tasks are done', 'Đã làm xong các việc này', 'これらの作業は完了しました'],
            'admin.backup.post_incident.marked_done' => ['Marked as done.', 'Đã đánh dấu là xong.', '完了にしました。'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function refusals(): array
    {
        return [
            'admin.backup.refused.origin_unverified' => ['The origin of this backup cannot be verified: confirm that you know where it comes from.', 'Không xác minh được nguồn gốc bản này: hãy xác nhận bạn biết rõ nó từ đâu.', 'このバックアップの出所を確認できません。出所を把握していることを確認してください。'],
            'admin.backup.refused.signature_mismatch' => ['The signature of this backup does not match: the file was changed. It is not restored.', 'Chữ ký của bản không khớp: file đã bị sửa. Không phục hồi.', 'このバックアップの署名が一致しません。ファイルが変更されています。復元しません。'],
            'admin.backup.refused.files_not_allowed' => ['Code and .env are not restored in this case (post-incident mode, or origin not verified).', 'Không phục hồi mã và .env trong trường hợp này (chế độ sau sự cố, hoặc chưa xác minh nguồn gốc).', 'この場合、コードと .env は復元されません（インシデント後モード、または出所未確認）。'],
            'admin.backup.refused.file_group_unknown' => ['This backup has no such files.', 'Bản này không có nhóm file đó.', 'このバックアップにそのファイルはありません。'],
            'admin.backup.refused.interactive_only' => ['Code and .env are restored only when a person answers the questions.', 'Mã và .env chỉ phục hồi khi có người trả lời các câu hỏi xác nhận.', 'コードと .env は、確認の質問に人が回答する場合のみ復元されます。'],
            'admin.backup.refused.already_in_catalogue' => ['This backup is already in the list.', 'Bản này đã có trong danh sách.', 'このバックアップはすでに一覧にあります。'],
            'admin.backup.refused.post_incident_level' => ['Post-incident mode restores the whole database.', 'Chế độ sau sự cố phục hồi toàn bộ database.', 'インシデント後モードはデータベース全体を復元します。'],
            'admin.backup.refused.upload_too_large' => ['The file is larger than allowed.', 'File lớn hơn mức cho phép.', 'ファイルが上限を超えています。'],
            'admin.backup.refused.upload_offset' => ['The upload is out of step: choose the file again.', 'Tải lên bị lệch: hãy chọn lại file.', 'アップロードがずれました。ファイルを選び直してください。'],
            'admin.backup.refused.upload_unknown' => ['This upload is no longer known: choose the file again.', 'Lượt tải lên này không còn: hãy chọn lại file.', 'このアップロードは無効です。ファイルを選び直してください。'],
            'admin.backup.refused.confirmation_required' => ['Not confirmed.', 'Chưa xác nhận.', '確認されていません。'],
        ];
    }
}
