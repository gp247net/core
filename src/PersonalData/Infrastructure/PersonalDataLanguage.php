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
            'admin.personal_data.status.failed' => ['The erasure stopped on an error. Click Delete again to continue where it stopped; if it keeps failing, check the system log.', 'Việc xoá dừng vì lỗi. Bấm Xoá lại để làm tiếp phần còn lại; nếu vẫn lỗi, hãy xem nhật ký hệ thống.', 'エラーで削除が止まりました。もう一度「削除」を押すと続きから処理します。失敗が続く場合はシステムログを確認してください。'],
            'admin.personal_data.source.login_failures' => ['Failed sign-ins', 'Lần đăng nhập sai', 'ログイン失敗'],
            'admin.backup.restore.reerased' => [
                'Customers erased since this backup: :count — their data is erased again (it continues in the background after your next clicks).',
                'Khách đã xoá sau ngày sao lưu: :count — dữ liệu của họ được xoá lại (tiếp tục chạy nền sau các thao tác tiếp theo của bạn).',
                'このバックアップ以降に削除された顧客: :count 人 — データを再度削除します（以降の操作の裏で続行します）。',
            ],
            'admin.backup.restore.reerased_unknown' => [
                'Erased customers could not be checked after this restore; see the system log.',
                'Không kiểm được khách đã xoá sau lần khôi phục này; hãy xem nhật ký hệ thống.',
                'この復元の後、削除済み顧客を確認できませんでした。システムログを確認してください。',
            ],
            'admin.backup.restore.reapply_failed' => [
                'Erasing again after a restore failed :count time(s): erased customers may be back. Check the system log, then restore again or delete those customers again.',
                'Xoá lại sau khôi phục đã thất bại :count lần: khách đã xoá có thể đã quay lại. Hãy xem nhật ký hệ thống, rồi khôi phục lại hoặc xoá lại các khách đó.',
                '復元後の再削除が :count 回失敗しました。削除済みの顧客が戻っている可能性があります。システムログを確認し、復元をやり直すか、その顧客を再度削除してください。',
            ],
            'admin.backup.restore.erased_refused' => [
                'Not added back: :count record(s) of erased customers.',
                'Không thêm lại: :count bản ghi của khách đã xoá.',
                '削除済み顧客のレコード :count 件は戻していません。',
            ],
            'admin.menu_titles.personal_data_requests' => ['Personal data requests', 'Yêu cầu dữ liệu cá nhân', '個人データの請求'],
            'admin.alerts.fix_personal_data' => ['Open personal data requests', 'Mở yêu cầu dữ liệu cá nhân', '個人データの請求を開く'],
            'admin.alerts.personal_data_failed' => [':count erasure(s) of personal data failed.', ':count lần xoá dữ liệu cá nhân bị lỗi.', '個人データの削除 :count 件が失敗しました。'],
            'admin.alerts.personal_data_overdue' => [':count personal data request(s) are past their due date.', ':count yêu cầu dữ liệu cá nhân đã quá hạn xử lý.', '個人データの請求 :count 件が期限を過ぎています。'],
            'admin.alerts.personal_data_undeclared' => [':count table(s) look like customer data but are not declared.', ':count bảng có vẻ chứa dữ liệu khách nhưng chưa được khai báo.', '顧客データらしい未申告のテーブルが :count 件あります。'],
            'admin.alerts.personal_data_ok' => ['Personal data requests are handled on time.', 'Yêu cầu dữ liệu cá nhân đang được xử lý đúng hạn.', '個人データの請求は期限内に処理されています。'],
            'admin.personal_data.settings.tab' => ['Personal data', 'Dữ liệu cá nhân', '個人データ'],
            'admin.personal_data.settings.title' => ['Personal data (whole site)', 'Dữ liệu cá nhân (toàn site)', '個人データ（サイト全体）'],
            'admin.personal_data.settings.erase_wait_days' => ['Days before an erasure runs', 'Số ngày chờ trước khi xoá', '削除実行までの日数'],
            'admin.personal_data.settings.erase_wait_days_hint' => ['0–30 days, so the customer can change their mind. Not legal advice: check the law where you sell.', '0–30 ngày, để khách kịp đổi ý. Không phải tư vấn pháp lý: hãy kiểm với luật nơi bạn bán hàng.', '0〜30 日。顧客が取り消せる期間です。法的助言ではありません。販売地域の法令を確認してください。'],
            'admin.personal_data.settings.overdue_days' => ['Days before a request is overdue', 'Số ngày trước khi yêu cầu bị quá hạn', '請求が期限切れになるまでの日数'],
            'admin.personal_data.settings.overdue_days_hint' => ['An unhandled request older than this is reported on System alerts (default 20, before the 30 days some laws allow).', 'Yêu cầu chưa xử lý quá số ngày này được báo ở System alerts (mặc định 20, trước mốc 30 ngày một số luật cho phép).', 'これより古い未処理の請求はシステム警告に表示されます（既定 20 日。一部の法令が認める 30 日より前）。'],
            'admin.personal_data.settings.not_customer_tables' => ['Tables marked as not customer data', 'Bảng được đánh dấu không phải dữ liệu khách', '顧客データではないとマークしたテーブル'],
            'personal_data.export.title' => ['Your personal data', 'Dữ liệu cá nhân của bạn', 'あなたの個人データ'],
            'personal_data.export.intro' => [
                'Everything this shop holds about you, as it was when this file was made. data.json is for programs, the csv folder opens in a spreadsheet.',
                'Toàn bộ dữ liệu cửa hàng đang giữ về bạn, tại thời điểm tạo tệp này. data.json dành cho phần mềm, thư mục csv mở được bằng bảng tính.',
                'このショップが保有しているあなたのデータ（このファイル作成時点）です。data.json はプログラム向け、csv フォルダーは表計算ソフトで開けます。',
            ],
            'personal_data.export.generated' => ['Made on :date', 'Tạo lúc :date', '作成日時 :date'],
            'personal_data.export.empty' => ['Nothing held.', 'Không có dữ liệu.', 'データはありません。'],
            'personal_data.export.readme' => [
                'Your personal data. index.html: readable page. data.json: all data, machine readable. csv/: one file per kind of data, for spreadsheets.',
                'Dữ liệu cá nhân của bạn. index.html: trang dễ đọc. data.json: toàn bộ dữ liệu cho máy đọc. csv/: mỗi loại dữ liệu một tệp, mở bằng bảng tính.',
                'あなたの個人データ。index.html：読みやすいページ。data.json：機械可読な全データ。csv/：データの種類ごとのファイル（表計算ソフト用）。',
            ],
            'admin.personal_data.requests.title' => ['Personal data requests', 'Yêu cầu dữ liệu cá nhân', '個人データの請求'],
            'admin.personal_data.requests.create_title' => ['New erasure request for a customer', 'Tạo yêu cầu xoá cho khách', '顧客の削除請求を作成'],
            'admin.personal_data.requests.customer_search' => ['Customer email or id', 'Email hoặc mã khách', '顧客のメールまたは ID'],
            'admin.personal_data.requests.find' => ['Find', 'Tìm', '検索'],
            'admin.personal_data.requests.customer_not_found' => ['No customer of your store matches.', 'Không có khách nào của cửa hàng bạn khớp.', 'あなたのストアに該当する顧客はいません。'],
            'admin.personal_data.requests.note' => ['Note (how the customer asked) — do not write the customer\'s personal details', 'Ghi chú (khách yêu cầu qua đâu) — không ghi thông tin cá nhân của khách', 'メモ（依頼の経路）— 顧客の個人情報は書かないでください'],
            'admin.personal_data.requests.now' => ['Erase now, without the waiting period', 'Xoá ngay, không chờ', '待機期間なしで今すぐ削除'],
            'admin.personal_data.requests.now_hint' => ['Erasing now cannot be undone. Without it, the request waits the days set in Configuration › Personal data.', 'Xoá ngay không hoàn tác được. Không chọn thì yêu cầu chờ số ngày đặt ở Cấu hình › Dữ liệu cá nhân.', '今すぐ削除は元に戻せません。選ばない場合は「設定 › 個人データ」の日数だけ待機します。'],
            'admin.personal_data.requests.create' => ['Create the request', 'Tạo yêu cầu', '請求を作成'],
            'admin.personal_data.requests.created' => ['Request created.', 'Đã tạo yêu cầu.', '請求を作成しました。'],
            'admin.personal_data.requests.all_statuses' => ['All statuses', 'Mọi trạng thái', 'すべての状態'],
            'admin.personal_data.requests.search' => ['Request id, customer email or id', 'Mã yêu cầu, email hoặc mã khách', '請求 ID、顧客のメールまたは ID'],
            'admin.personal_data.requests.overdue_only' => ['Overdue only', 'Chỉ quá hạn', '期限切れのみ'],
            'admin.personal_data.requests.empty' => ['No request.', 'Chưa có yêu cầu.', '請求はありません。'],
            'admin.personal_data.requests.col.id' => ['Request', 'Mã yêu cầu', '請求'],
            'admin.personal_data.requests.col.kind' => ['Kind', 'Loại', '種類'],
            'admin.personal_data.requests.col.source' => ['Sent by', 'Nguồn', '送信元'],
            'admin.personal_data.requests.col.customer' => ['Customer', 'Khách', '顧客'],
            'admin.personal_data.requests.col.status' => ['Status', 'Trạng thái', '状態'],
            'admin.personal_data.requests.col.received_at' => ['Received', 'Ngày nhận', '受付日'],
            'admin.personal_data.requests.col.due_at' => ['Due', 'Hạn xử lý', '期限'],
            'admin.personal_data.requests.kind.export' => ['Export', 'Xuất dữ liệu', 'エクスポート'],
            'admin.personal_data.requests.kind.erase' => ['Erase', 'Xoá dữ liệu', '削除'],
            'admin.personal_data.settings.guest_form' => ['Request form for shoppers without an account', 'Form yêu cầu cho khách không có tài khoản', 'アカウントのないお客様向けの請求フォーム'],
            'admin.personal_data.settings.guest_form_hint' => [
                'A public page where a guest shopper asks for, or asks to erase, the data of their orders (checked by email). Off, or with outgoing email off, the page does not exist.',
                'Trang công khai để khách mua không tạo tài khoản xin tải hoặc xin xoá dữ liệu đơn hàng của mình (xác minh qua email). Tắt, hoặc khi site tắt gửi email, trang này không tồn tại.',
                'アカウントを作らずに購入したお客様が、注文データのダウンロードや削除を申請できる公開ページです（メールで確認）。オフ、またはメール送信がオフのときはページがありません。',
            ],
            'admin.personal_data.requests.approve' => ['Approve the erasure', 'Duyệt xoá', '削除を承認'],
            'admin.personal_data.requests.reject' => ['Turn down', 'Từ chối', '却下'],
            'admin.personal_data.requests.reject_reason' => ['Reason (sent to the shopper)', 'Lý do (gửi cho khách)', '理由（お客様に送信されます）'],
            'admin.personal_data.requests.reason_required' => ['Write the reason first.', 'Hãy nhập lý do trước.', '先に理由を入力してください。'],
            'admin.personal_data.requests.create_export' => ['Prepare the data package', 'Chuẩn bị gói dữ liệu', 'データパッケージを作成'],
            'admin.personal_data.requests.export_hint' => [
                'A ZIP with everything the shop holds about the customer (JSON, CSV, a readable page). Downloadable once, for 24 hours; one package per customer per 24 hours.',
                'Tệp ZIP chứa mọi dữ liệu cửa hàng giữ về khách (JSON, CSV, một trang dễ đọc). Tải được một lần, trong 24 giờ; mỗi khách một gói trong 24 giờ.',
                'ショップが保有する顧客のデータをすべて含む ZIP（JSON、CSV、閲覧用ページ）。24 時間以内に 1 回だけダウンロードできます。顧客ごとに 24 時間に 1 つまでです。',
            ],
            'admin.personal_data.requests.export_reused' => [
                'A package was already made for this customer in the last 24 hours: it is shown below.',
                'Khách này đã có gói trong 24 giờ qua: gói đó hiện ở bên dưới.',
                'この顧客には過去 24 時間以内に作成したパッケージがあります。下に表示しています。',
            ],
            'admin.personal_data.requests.export_unavailable' => [
                'Data packages cannot be made on this server (the PHP zip extension is missing, or the site is not updated).',
                'Máy chủ này chưa tạo được gói dữ liệu (thiếu tiện ích zip của PHP, hoặc site chưa cập nhật).',
                'このサーバーではデータパッケージを作成できません（PHP の zip 拡張がないか、サイトが未更新です）。',
            ],
            'admin.personal_data.requests.package.building' => ['The package is being prepared…', 'Đang chuẩn bị gói…', 'パッケージを準備しています…'],
            'admin.personal_data.requests.package.ready' => ['The package is ready.', 'Gói đã sẵn sàng.', 'パッケージの準備ができました。'],
            'admin.personal_data.requests.package.downloaded' => ['The package was downloaded and removed from the server.', 'Gói đã được tải và đã xoá khỏi máy chủ.', 'パッケージはダウンロード済みで、サーバーから削除されました。'],
            'admin.personal_data.requests.package.expired' => ['The package was not downloaded in time and was removed.', 'Gói không được tải kịp hạn và đã bị xoá.', 'パッケージは期限内にダウンロードされず、削除されました。'],
            'admin.personal_data.requests.package.failed' => ['The package could not be made.', 'Không tạo được gói.', 'パッケージを作成できませんでした。'],
            'admin.personal_data.requests.package_expires' => ['available until :at', 'tải được tới :at', ':at まで'],
            'admin.personal_data.requests.download' => ['Download the package', 'Tải gói', 'パッケージをダウンロード'],
            'admin.personal_data.requests.link_invalid' => [
                'This link is no longer valid (already used, expired, or not yours).',
                'Link này không còn dùng được (đã dùng, hết hạn, hoặc không phải của bạn).',
                'このリンクは無効です（使用済み、期限切れ、または別の人のリンク）。',
            ],
            'admin.alerts.personal_data_no_zip' => [
                'The PHP zip extension is missing: customers cannot download their data.',
                'Thiếu tiện ích zip của PHP: khách không tải được dữ liệu của mình.',
                'PHP の zip 拡張がありません。顧客は自分のデータをダウンロードできません。',
            ],
            'admin.personal_data.requests.source.customer' => ['Customer (website)', 'Khách (website)', '顧客（Web）'],
            'admin.personal_data.requests.source.api' => ['Customer (app / API)', 'Khách (ứng dụng / API)', '顧客（アプリ / API）'],
            'admin.personal_data.requests.source.guest' => ['Guest (form)', 'Khách vãng lai (form)', 'ゲスト（フォーム）'],
            'admin.personal_data.requests.source.admin' => ['Administrator', 'Quản trị viên', '管理者'],
            'admin.personal_data.requests.status.pending_verification' => ['Waiting for email check', 'Chờ xác minh email', 'メール確認待ち'],
            'admin.personal_data.requests.status.pending_approval' => ['Waiting for approval', 'Chờ duyệt', '承認待ち'],
            'admin.personal_data.requests.status.waiting' => ['Waiting (customer can change their mind)', 'Đang chờ đổi ý', '取消可能期間中'],
            'admin.personal_data.requests.status.on_hold' => ['On hold until orders are closed', 'Chờ đơn hàng đóng', '注文完了待ち'],
            'admin.personal_data.requests.status.processing' => ['Processing', 'Đang xử lý', '処理中'],
            'admin.personal_data.requests.status.completed' => ['Completed', 'Hoàn tất', '完了'],
            'admin.personal_data.requests.status.rejected' => ['Rejected', 'Bị từ chối', '却下'],
            'admin.personal_data.requests.status.cancelled' => ['Cancelled', 'Đã huỷ', '取消'],
            'admin.personal_data.requests.status.failed' => ['Failed', 'Lỗi', '失敗'],
            'admin.personal_data.requests.status.queued' => ['Started', 'Đã bắt đầu', '開始しました'],
            'admin.personal_data.requests.status.running' => ['Erasing…', 'Đang xoá…', '削除中…'],
            'admin.personal_data.requests.status.done' => ['Erased.', 'Đã xoá.', '削除しました。'],
            'admin.personal_data.requests.channel.email' => ['Email', 'Email', 'メール'],
            'admin.personal_data.requests.channel.phone' => ['Phone', 'Điện thoại', '電話'],
            'admin.personal_data.requests.channel.store' => ['In store', 'Tại cửa hàng', '店頭'],
            'admin.personal_data.requests.channel.other' => ['Other', 'Khác', 'その他'],
            'admin.personal_data.requests.detail_title' => ['Request :id', 'Yêu cầu :id', '請求 :id'],
            'admin.personal_data.requests.runs_at' => ['runs after :at', 'chạy sau :at', ':at 以降に実行'],
            'admin.personal_data.requests.execute' => ['Erase now', 'Xoá ngay', '今すぐ削除'],
            'admin.personal_data.requests.cancel' => ['Cancel the request', 'Huỷ yêu cầu', '請求を取り消す'],
            'admin.personal_data.requests.retry' => ['Run again', 'Chạy lại', '再実行'],
            'admin.personal_data.requests.history' => ['History', 'Lịch sử xử lý', '処理履歴'],
            'admin.personal_data.requests.undeclared_title' => ['Customer data nobody declared', 'Dữ liệu khách chưa khai báo', '未申告の顧客データ'],
            'admin.personal_data.requests.undeclared_check' => ['Check the database', 'Kiểm tra cơ sở dữ liệu', 'データベースを確認'],
            'admin.personal_data.requests.undeclared_none' => ['Every table that looks like customer data is declared.', 'Mọi bảng có vẻ chứa dữ liệu khách đều đã được khai báo.', '顧客データらしいテーブルはすべて申告済みです。'],
            'admin.personal_data.requests.undeclared_help' => ['These tables (often of a plugin) are not erased or exported automatically. Ask the plugin author to declare them, handle requests for them by hand, or mark a table that holds no customer data.', 'Các bảng này (thường của plugin) không được tự động xoá / xuất. Hãy nhờ tác giả plugin khai báo, xử lý tay các yêu cầu liên quan, hoặc đánh dấu bảng không chứa dữ liệu khách.', 'これらのテーブル（多くはプラグイン）は自動で削除・エクスポートされません。作者に申告を依頼するか、手動で対応するか、顧客データでないテーブルをマークしてください。'],
            'admin.personal_data.requests.not_customer_data' => ['Not customer data', 'Không phải dữ liệu khách', '顧客データではない'],
            'admin.personal_data.requests.administrator_only' => ['Only an Administrator can do this.', 'Chỉ Administrator làm được việc này.', 'この操作は Administrator のみ可能です。'],
            'admin.personal_data.requests.table_marked' => ['Saved.', 'Đã lưu.', '保存しました。'],
            'admin.personal_data.requests.not_allowed' => ['This request cannot do that from its current status.', 'Yêu cầu ở trạng thái hiện tại không làm được việc này.', '現在の状態ではこの操作はできません。'],
            'admin.menu_titles.personal_data_retention' => ['Data retention', 'Hạn lưu giữ dữ liệu', 'データ保存期間'],
            'admin.personal_data.requests.source.retention' => ['Unused account (retention)', 'Tài khoản lâu không dùng', '長期間未使用のアカウント'],
            'admin.personal_data.retention.title' => ['Data retention', 'Hạn lưu giữ dữ liệu', 'データ保存期間'],
            'admin.personal_data.retention.help' => ['Personal data is kept only as long as needed. Cleaning runs by itself while the site is visited (no cron needed), a little at a time. An item that is new on this site cleans nothing for its first :days days. Not legal advice: check the law where you sell.', 'Dữ liệu cá nhân chỉ được giữ trong thời gian cần thiết. Việc dọn tự chạy khi có người truy cập site (không cần cron), mỗi lần một ít. Mục mới xuất hiện trên site không dọn gì trong :days ngày đầu. Không phải tư vấn pháp lý: hãy kiểm với luật nơi bạn bán hàng.', '個人データは必要な期間だけ保存します。整理はサイトへのアクセス時に少しずつ自動で行われます（cron 不要）。このサイトで新しく現れた項目は、最初の :days 日間は何も整理しません。法的助言ではありません。販売地域の法令を確認してください。'],
            'admin.personal_data.retention.col.item' => ['Data', 'Dữ liệu', 'データ'],
            'admin.personal_data.retention.col.enabled' => ['On', 'Bật', '有効'],
            'admin.personal_data.retention.col.days' => ['Days kept', 'Số ngày giữ', '保存日数'],
            'admin.personal_data.retention.col.last_run' => ['Last run', 'Lần chạy gần nhất', '前回の実行'],
            'admin.personal_data.retention.col.rows' => ['Last / total', 'Lần gần nhất / tổng', '前回 / 合計'],
            'admin.personal_data.retention.col.actions' => ['Actions', 'Thao tác', '操作'],
            'admin.personal_data.retention.action.delete' => ['Deleted', 'Xoá', '削除'],
            'admin.personal_data.retention.action.anonymise' => ['Anonymised (amounts kept)', 'Ẩn danh (giữ số tiền)', '匿名化（金額は保持）'],
            'admin.personal_data.retention.action.erase_accounts' => ['Account erased after a notice', 'Xoá tài khoản sau khi báo trước', '通知後にアカウントを削除'],
            'admin.personal_data.retention.risky' => ['Removes business data', 'Xoá dữ liệu kinh doanh', '業務データを削除'],
            'admin.personal_data.retention.fixed' => ['When it expires', 'Khi hết hạn', '有効期限切れ時'],
            'admin.personal_data.retention.range' => [':min–:max days', ':min–:max ngày', ':min〜:max 日'],
            'admin.personal_data.retention.first_clean' => ['First clean after :at', 'Lần dọn đầu tiên sau :at', '初回の整理は :at 以降'],
            'admin.personal_data.retention.error' => ['Last run failed (:error). See the system log.', 'Lần chạy gần nhất bị lỗi (:error). Xem nhật ký hệ thống.', '前回の実行に失敗しました（:error）。システムログを確認してください。'],
            'admin.personal_data.retention.save' => ['Save', 'Lưu', '保存'],
            'admin.personal_data.retention.run_now' => ['Run now', 'Chạy ngay', '今すぐ実行'],
            'admin.personal_data.retention.ran' => ['Done: :rows handled.', 'Xong: đã xử lý :rows.', '完了: :rows 件を処理しました。'],
            'admin.personal_data.retention.ran_more' => [':rows handled; the rest continues by itself.', 'Đã xử lý :rows; phần còn lại tự chạy tiếp.', ':rows 件を処理しました。残りは自動で続きます。'],
            'admin.personal_data.retention.run_failed' => ['The run failed. See the system log.', 'Lần chạy bị lỗi. Xem nhật ký hệ thống.', '実行に失敗しました。システムログを確認してください。'],
            'admin.personal_data.retention.is_off' => ['Turn the item on and save it first.', 'Hãy bật mục và lưu trước.', '先に項目を有効にして保存してください。'],
            'admin.personal_data.retention.empty' => ['No retention item is declared.', 'Chưa có mục hạn lưu giữ nào được khai báo.', '保存期間の項目が宣言されていません。'],
            'admin.personal_data.retention.confirm_title' => ['Confirm the change', 'Xác nhận thay đổi', '変更の確認'],
            'admin.personal_data.retention.confirm_records' => [':count record(s) are past this period now and will lose the buyer\'s personal data (amounts are kept). This cannot be undone.', 'Hiện có :count bản ghi đã quá hạn này và sẽ mất dữ liệu cá nhân của người mua (giữ số tiền). Không hoàn tác được.', '現在 :count 件がこの期間を過ぎており、購入者の個人データが削除されます（金額は保持）。元に戻せません。'],
            'admin.personal_data.retention.confirm_accounts' => [':count account(s) have not been used for this period. Accounts are judged only on activity after today: the first notices go out when an account has been unused for the whole period since today, and it is erased :days days after its notice unless the customer signs in. Customers without a verified email get no notice.', 'Có :count tài khoản đã không dùng trong khoảng này. Tài khoản chỉ được tính từ hôm nay: thông báo đầu tiên gửi khi tài khoản không dùng trọn khoảng đó kể từ hôm nay, và bị xoá :days ngày sau thông báo nếu khách không đăng nhập. Khách chưa xác minh email sẽ không nhận được thông báo.', 'この期間使われていないアカウントが :count 件あります。判定は今日以降の利用のみで行います。今日から期間全体使われなかった時点で最初の通知を送り、顧客がログインしなければ通知の :days 日後に削除します。メール未確認の顧客には通知されません。'],
            'admin.personal_data.retention.confirm_backup' => ['If backups are on, the data concerned is saved first.', 'Nếu đang bật sao lưu, dữ liệu liên quan sẽ được sao lưu trước.', 'バックアップが有効な場合、対象データを先に保存します。'],
            'admin.personal_data.retention.confirm' => ['Confirm and save', 'Xác nhận và lưu', '確認して保存'],
            'admin.personal_data.retention.early_title' => ['Clean before the first date?', 'Dọn trước ngày dọn đầu tiên?', '初回日より前に整理しますか？'],
            'admin.personal_data.retention.early_help' => ['This item waits so a site that was just updated does not lose old data at once. Clean now only if you have checked what it removes.', 'Mục này đang chờ để site vừa cập nhật không mất ngay dữ liệu cũ. Chỉ dọn ngay khi bạn đã kiểm những gì sẽ bị xoá.', 'この項目は、更新直後のサイトで古いデータがすぐ消えないよう待機しています。削除内容を確認した場合のみ今すぐ整理してください。'],
            'admin.personal_data.retention.early_confirm' => ['Clean now', 'Dọn ngay', '今すぐ整理'],
            'admin.personal_data.retention.requests' => ['Personal data requests and their history', 'Yêu cầu dữ liệu cá nhân và lịch sử xử lý', '個人データの請求と処理履歴'],
            'admin.personal_data.retention.requests_help' => ['Kept as proof of how requests were handled.', 'Giữ làm bằng chứng đã xử lý yêu cầu.', '請求への対応の記録として保存します。'],
            'admin.personal_data.retention.requests_period' => ['5 years (fixed)', '5 năm (cố định)', '5 年（固定）'],
            'admin.personal_data.retention.item.admin_log' => ['Admin activity log', 'Nhật ký thao tác quản trị', '管理操作ログ'],
            'admin.alerts.personal_data_retention_failed' => ['Data retention: :count item(s) keep failing.', 'Hạn lưu giữ: :count mục bị lỗi liên tiếp.', 'データ保存期間: :count 件の項目が続けて失敗しています。'],
            'admin.alerts.personal_data_retention_soon' => ['Data retention: the first clean starts within :days days (:count record(s)).', 'Hạn lưu giữ: lần dọn đầu tiên bắt đầu trong :days ngày (:count bản ghi).', 'データ保存期間: :days 日以内に初回の整理が始まります（:count 件）。'],
            'admin.alerts.personal_data_retention_no_backup' => ['Data retention removes business data while backups are off.', 'Hạn lưu giữ đang xoá dữ liệu kinh doanh trong khi sao lưu đang tắt.', 'バックアップが無効のまま、データ保存期間が業務データを削除しています。'],
            'admin.alerts.fix_personal_data_retention' => ['Open data retention', 'Mở hạn lưu giữ', 'データ保存期間を開く'],
            'admin.personal_data.not_ready' => ['Run php artisan gp247:update first.', 'Hãy chạy php artisan gp247:update trước.', '先に php artisan gp247:update を実行してください。'],
        ];
    }

    /**
     * @param string|array<string, string> $label A source label: a language key, or texts by locale.
     * @return string The text for the current locale (then English, then the first text given).
     */
    public static function label(string|array $label): string
    {
        if (is_string($label)) {
            return $label === '' ? '' : (string) gp247_language_render($label);
        }
        $locale = app()->getLocale();

        return (string) ($label[$locale] ?? $label['en'] ?? (reset($label) ?: ''));
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
