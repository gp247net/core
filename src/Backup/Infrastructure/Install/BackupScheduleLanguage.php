<?php

namespace GP247\Core\Backup\Infrastructure\Install;

/**
 * Texts of Bolt BK-7, en / vi / ja: the "Schedule" block of the backup settings, the recovery point
 * on the backup screen, the "too old" e-mail, the answers of the "before change" snapshot and the
 * "backup folder reachable over the web" row of System alerts. Merged by BackupScreenLanguage::rows().
 * Each row: code => [en, vi, ja].
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule, US-backup-admin-doctor-checks, US-backup-engine-pre-change-snapshot
 */
final class BackupScheduleLanguage
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rows(): array
    {
        return self::settings() + self::recoveryPoint() + self::other();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function settings(): array
    {
        $p = 'admin.backup.schedule.';

        return [
            $p . 'title' => ['Schedule', 'Lịch sao lưu', 'スケジュール'],
            $p . 'mode' => ['Scheduled backups', 'Sao lưu theo lịch', 'スケジュールバックアップ'],
            $p . 'mode.off' => ['Off (manual backups only)', 'Tắt (chỉ sao lưu thủ công)', 'オフ（手動バックアップのみ）'],
            $p . 'mode.visit' => ['When an administrator opens the admin (no cron needed)', 'Khi quản trị viên mở trang quản trị (không cần cron)', '管理者が管理画面を開いたとき（cron 不要）'],
            $p . 'mode.cron' => ['At a fixed hour (needs cron on the server)', 'Vào giờ cố định (cần cron trên máy chủ)', '決まった時刻（サーバーの cron が必要）'],
            $p . 'mode_help.off' => ['No backup is made unless someone creates one. Not recommended.', 'Không có bản mới cho tới khi có người tạo. Không khuyến nghị.', '誰かが作成しない限りバックアップは作られません。推奨しません。'],
            $p . 'mode_help.visit' => ['Works on every hosting. The backup runs in the background, a little at a time, while an administrator has the admin open. If nobody opens the admin, no new backup is made.', 'Chạy được trên mọi hosting. Bản sao lưu chạy nền, từng phần nhỏ, trong lúc quản trị viên mở trang quản trị. Không ai mở trang quản trị thì không có bản mới.', 'どのホスティングでも動作します。管理者が管理画面を開いている間、バックグラウンドで少しずつ実行されます。誰も管理画面を開かなければ、新しいバックアップは作られません。'],
            $p . 'mode_help.cron' => ['Runs at the chosen hour even when nobody visits, if the server runs Laravel\'s scheduler every minute (see below).', 'Chạy vào giờ đã chọn kể cả khi không ai truy cập, nếu máy chủ chạy bộ lập lịch của Laravel mỗi phút (xem bên dưới).', 'サーバーが Laravel のスケジューラーを毎分実行していれば、誰もアクセスしなくても指定時刻に実行されます（下記参照）。'],
            $p . 'hours' => ['How often', 'Tần suất', '頻度'],
            $p . 'every' => ['Every :hours hours', 'Mỗi :hours giờ', ':hours 時間ごと'],
            $p . 'at' => ['Hour (24 h, server time)', 'Giờ chạy (24 giờ, giờ máy chủ)', '時刻（24 時間表記、サーバー時刻）'],
            $p . 'kind' => ['What a scheduled backup holds', 'Bản theo lịch chứa gì', 'スケジュールバックアップの内容'],
            $p . 'kind.full' => ['Whole site: database (without logs), uploaded files, extensions, .env, composer.lock', 'Toàn bộ site: cơ sở dữ liệu (bỏ log), file tải lên, extension, .env, composer.lock', 'サイト全体：データベース（ログを除く）、アップロードファイル、拡張機能、.env、composer.lock'],
            $p . 'kind.data' => ['Database only (without logs, no files — lighter)', 'Chỉ cơ sở dữ liệu (bỏ log, không file — nhẹ hơn)', 'データベースのみ（ログを除く、ファイルなし — 軽量）'],
            $p . 'save' => ['Save schedule', 'Lưu lịch', 'スケジュールを保存'],
            $p . 'saved' => ['Schedule saved.', 'Đã lưu lịch.', 'スケジュールを保存しました。'],
            $p . 'invalid' => ['This value is not allowed.', 'Giá trị này không hợp lệ.', 'この値は使用できません。'],
            $p . 'not_set_up' => ['Scheduled backups start only after a backup password is set up on the backup screen.', 'Sao lưu theo lịch chỉ bắt đầu sau khi đặt mật khẩu sao lưu ở màn sao lưu.', 'スケジュールバックアップは、バックアップ画面でバックアップパスワードを設定した後に開始されます。'],
            $p . 'cron_guide' => ['Add this line to the crontab of the server once (your host\'s control panel usually has a "Cron jobs" page):', 'Thêm dòng này vào crontab của máy chủ một lần (bảng điều khiển hosting thường có mục "Cron jobs"):', 'このサーバーの crontab に次の行を一度追加してください（ホスティングの管理パネルに「Cron ジョブ」ページがあるのが一般的です）:'],
            $p . 'cron_task' => ['The scheduler then runs ":command" at the chosen hour. The Scheduled tasks plugin, when installed, lists it with the other tasks.', 'Bộ lập lịch sẽ chạy ":command" vào giờ đã chọn. Plugin Scheduled tasks (nếu cài) liệt kê nó cùng các tác vụ khác.', 'スケジューラーが指定時刻に「:command」を実行します。Scheduled tasks プラグインを導入していれば、他のタスクと一緒に一覧表示されます。'],
            $p . 'cron_missed' => ['Cron mode is on, but no scheduled backup was made for two cycles: the server probably does not run "schedule:run". Check the crontab, or switch to "when an administrator opens the admin".', 'Đang chọn chế độ cron nhưng hai chu kỳ liền không có bản theo lịch: có lẽ máy chủ chưa chạy "schedule:run". Hãy kiểm crontab, hoặc chuyển sang "khi quản trị viên mở trang quản trị".', 'cron モードですが、2 周期続けてスケジュールバックアップが作られていません。サーバーが「schedule:run」を実行していない可能性があります。crontab を確認するか、「管理者が管理画面を開いたとき」に切り替えてください。'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function recoveryPoint(): array
    {
        $p = 'admin.backup.schedule.rpo.';

        return [
            $p . 'off' => ['No schedule: if something goes wrong, you can only go back to the newest backup someone made by hand.', 'Chưa có lịch: nếu có sự cố, bạn chỉ quay về được bản mới nhất do người tạo thủ công.', 'スケジュールなし：問題が起きた場合、手動で作成された最新のバックアップまでしか戻せません。'],
            $p . 'visit' => ['A new backup every :hours hours while an administrator opens the admin. If nobody opens it for several days, there is no new backup for those days.', 'Bản mới mỗi :hours giờ khi có quản trị viên mở trang quản trị. Không ai mở trong nhiều ngày thì những ngày đó không có bản mới.', '管理者が管理画面を開いていれば :hours 時間ごとに新しいバックアップが作られます。数日間誰も開かなければ、その期間の新しいバックアップはありません。'],
            $p . 'cron' => ['A new backup every :hours hours from :at, as long as the server runs "schedule:run" every minute.', 'Bản mới mỗi :hours giờ từ :at, miễn là máy chủ chạy "schedule:run" mỗi phút.', 'サーバーが毎分「schedule:run」を実行している限り、:at から :hours 時間ごとに新しいバックアップが作られます。'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function other(): array
    {
        return [
            'admin.backup.mail.stale_title' => ['No recent backup', 'Không có bản sao lưu gần đây', '最近のバックアップがありません'],
            'admin.backup.mail.stale_body' => ['The newest verified backup of :site is :hours hours old (schedule: :mode). Open the backup screen to create one now, and check the schedule.', 'Bản sao lưu đã kiểm mới nhất của :site đã :hours giờ (lịch: :mode). Hãy mở màn sao lưu để tạo bản mới ngay và kiểm lại lịch.', ':site の検証済み最新バックアップは :hours 時間前のものです（スケジュール：:mode）。バックアップ画面を開いて今すぐ作成し、スケジュールを確認してください。'],
            'admin.backup.pre_change.not_set_up' => ['No backup was made before this change: the backup password is not set up yet (Admin > Backup).', 'Chưa tạo bản sao lưu trước thay đổi này: chưa đặt mật khẩu sao lưu (Quản trị > Sao lưu).', 'この変更の前にバックアップは作成されていません。バックアップパスワードが未設定です（管理 > バックアップ）。'],
            'admin.backup.pre_change.done' => ['A backup of the data this change may touch was made first (:id).', 'Đã tạo bản sao lưu phần dữ liệu thay đổi này có thể chạm tới (:id).', 'この変更が影響し得るデータのバックアップを先に作成しました（:id）。'],
            'admin.backup.pre_change.running' => ['A backup of the data this change may touch is being made. Do the same action again in a few seconds.', 'Đang tạo bản sao lưu phần dữ liệu thay đổi này có thể chạm tới. Hãy thao tác lại sau vài giây.', 'この変更が影響し得るデータのバックアップを作成中です。数秒後にもう一度操作してください。'],
            'admin.backup.pre_change.failed' => ['The backup before this change could not be made (:reason). You can go on without it, at your own risk.', 'Không tạo được bản sao lưu trước thay đổi này (:reason). Bạn vẫn có thể tiếp tục mà không có bản sao lưu, tự chịu rủi ro.', 'この変更前のバックアップを作成できませんでした（:reason）。バックアップなしで続行することもできますが、自己責任となります。'],
            'admin.alerts.backup_vault_exposed' => ['Backup files can be downloaded from the web', 'Có thể tải file sao lưu qua web', 'バックアップファイルが Web からダウンロードできます'],
            'admin.alerts.backup_vault_ok' => ['Backup folder not reachable from the web', 'Thư mục sao lưu không truy cập được từ web', 'バックアップフォルダーは Web からアクセスできません'],
        ];
    }
}
