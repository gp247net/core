<?php

namespace GP247\Core\Backup\Infrastructure\Install;

/**
 * Texts of the external destination (Bolt BK-6), en / vi / ja: settings block, connection test,
 * write-only guidance, copy state on the backup screen, archives at the destination on the restore
 * screen, e-mail. Merged by BackupScreenLanguage::rows(). Each row: code => [en, vi, ja].
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-immutable-guide, US-backup-storage-fetch-remote
 */
final class BackupOffsiteLanguage
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function rows(): array
    {
        return self::settings() + self::report() + self::guide() + self::screens();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function settings(): array
    {
        $p = 'admin.backup.offsite.';

        return [
            $p . 'title' => ['External destination', 'Đích ngoài', '外部の保存先'],
            $p . 'intro' => ['Every verified backup is also copied, still encrypted, to one place outside this server (the "1 off-site" of the 3-2-1 rule). The local copy is always kept.', 'Mỗi bản sao lưu đã kiểm còn được chép (vẫn mã hoá) ra một nơi ngoài máy chủ này (chữ "1 ngoài máy chủ" của quy tắc 3-2-1). Bản cục bộ luôn được giữ.', '検証済みのバックアップは、暗号化されたまま、このサーバー外の 1 か所にもコピーされます（3-2-1 ルールの「オフサイト 1」）。ローカルのコピーは常に保持されます。'],
            $p . 'type' => ['Type', 'Loại', '種類'],
            $p . 'type.none' => ['None (local only)', 'Không có (chỉ cục bộ)', 'なし（ローカルのみ）'],
            $p . 'type.s3' => ['S3-compatible (AWS S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO…)', 'S3-compatible (AWS S3, Cloudflare R2, Backblaze B2, Wasabi, MinIO…)', 'S3 互換（AWS S3、Cloudflare R2、Backblaze B2、Wasabi、MinIO…）'],
            $p . 'type.sftp' => ['SFTP server or NAS', 'Máy chủ SFTP hoặc NAS', 'SFTP サーバーまたは NAS'],
            $p . 'unavailable' => ['not available', 'không khả dụng', '利用不可'],
            $p . 'install' => [':type needs a library that is not installed on this site. Ask your developer or host to run:', ':type cần một thư viện chưa cài trên site này. Hãy nhờ lập trình viên hoặc nhà cung cấp hosting chạy:', ':type には、このサイトに未インストールのライブラリが必要です。開発者またはホスティング会社に次の実行を依頼してください:'],
            $p . 'field.endpoint' => ['Endpoint URL', 'Địa chỉ endpoint', 'エンドポイント URL'],
            $p . 'field.region' => ['Region', 'Vùng (region)', 'リージョン'],
            $p . 'field.bucket' => ['Bucket', 'Bucket', 'バケット'],
            $p . 'field.prefix' => ['Folder in the bucket (optional)', 'Thư mục trong bucket (tuỳ chọn)', 'バケット内のフォルダー（任意）'],
            $p . 'field.path_style' => ['Path-style addresses (MinIO and some providers)', 'Địa chỉ kiểu path-style (MinIO và vài nhà cung cấp)', 'パス形式のアドレス（MinIO など）'],
            $p . 'field.key' => ['Access key ID', 'Access key ID', 'アクセスキー ID'],
            $p . 'field.secret' => ['Secret access key', 'Secret access key', 'シークレットアクセスキー'],
            $p . 'field.host' => ['Server', 'Máy chủ', 'サーバー'],
            $p . 'field.port' => ['Port', 'Cổng', 'ポート'],
            $p . 'field.username' => ['User name', 'Tên đăng nhập', 'ユーザー名'],
            $p . 'field.root' => ['Folder on the server', 'Thư mục trên máy chủ', 'サーバー上のフォルダー'],
            $p . 'field.password' => ['Password', 'Mật khẩu', 'パスワード'],
            $p . 'field.private_key' => ['Private key (instead of a password)', 'Khoá riêng (thay cho mật khẩu)', '秘密鍵（パスワードの代わり）'],
            $p . 'field.passphrase' => ['Private key passphrase', 'Passphrase của khoá riêng', '秘密鍵のパスフレーズ'],
            $p . 'help.endpoint' => ['Empty for AWS S3. R2: https://<account>.r2.cloudflarestorage.com · B2: https://s3.<region>.backblazeb2.com', 'Để trống với AWS S3. R2: https://<account>.r2.cloudflarestorage.com · B2: https://s3.<region>.backblazeb2.com', 'AWS S3 の場合は空欄。R2: https://<account>.r2.cloudflarestorage.com · B2: https://s3.<region>.backblazeb2.com'],
            $p . 'help.prefix' => ['Several sites can share one bucket: each one writes in its own sub-folder.', 'Nhiều site dùng chung một bucket được: mỗi site ghi vào thư mục con riêng.', '複数のサイトで 1 つのバケットを共有できます。各サイトは専用のサブフォルダーに書き込みます。'],
            $p . 'secret_kept' => ['Stored — leave empty to keep', 'Đã lưu — để trống để giữ', '保存済み — 空欄のままで維持'],
            $p . 'secret_stored' => ['A value is stored (never shown again).', 'Đã có giá trị lưu (không bao giờ hiện lại).', '値が保存されています（再表示されません）。'],
            $p . 'secret_help' => ['Secrets are stored encrypted and never shown again. If you change where the data goes (endpoint, bucket, server, port or user), type the secrets again.', 'Khoá bí mật được lưu mã hoá và không hiện lại. Đổi nơi nhận dữ liệu (endpoint, bucket, máy chủ, cổng hoặc tên đăng nhập) thì phải nhập lại khoá bí mật.', '秘密情報は暗号化して保存され、再表示されません。保存先（エンドポイント、バケット、サーバー、ポート、ユーザー）を変更した場合は、秘密情報を再入力してください。'],
            $p . 'save' => ['Save and test', 'Lưu và kiểm tra', '保存してテスト'],
            $p . 'test' => ['Test the connection', 'Kiểm tra kết nối', '接続をテスト'],
            $p . 'remove' => ['Stop copying off-site', 'Ngừng chép ra ngoài', 'オフサイトへのコピーを停止'],
            $p . 'remove_confirm' => ['Forget this destination? Nothing is deleted there; new backups stay local only.', 'Bỏ đích ngoài này? Không xoá gì ở đích; các bản mới chỉ lưu cục bộ.', 'この保存先の設定を削除しますか？保存先のデータは削除されません。新しいバックアップはローカルのみになります。'],
            $p . 'saved' => ['Destination saved.', 'Đã lưu đích ngoài.', '保存先を保存しました。'],
            $p . 'removed' => ['Destination removed. Copies already made stay at the destination.', 'Đã bỏ đích ngoài. Các bản đã chép vẫn còn ở đích.', '保存先を削除しました。コピー済みのデータは保存先に残ります。'],
            $p . 'host_key_accepted' => ['The new server key is pinned.', 'Đã ghim khoá máy chủ mới.', '新しいサーバーキーを固定しました。'],
            $p . 'host_key_mismatch' => ['The saved server now shows another key than the one displayed. Test the connection again before accepting.', 'Máy chủ đã lưu hiện trả về khoá khác với khoá đang hiển thị. Hãy kiểm tra kết nối lại trước khi chấp nhận.', '保存済みのサーバーは表示中とは別のキーを返しています。承認する前に接続テストをやり直してください。'],
            $p . 'secret_again' => ['For your safety the secret you typed was not kept. After confirming your password, type it again.', 'Để an toàn, khoá bí mật vừa nhập không được giữ lại. Sau khi xác nhận mật khẩu, hãy nhập lại.', '安全のため、入力した秘密情報は保持していません。パスワードを確認した後、もう一度入力してください。'],
            $p . 'moved' => ['The destination now points to another place: earlier copies count as not copied there. Use "Copy again" on the backup screen for the archives you need there.', 'Đích ngoài đã trỏ sang nơi khác: các bản đã chép trước đây được tính là chưa chép tới nơi mới. Dùng "Chép lại" trên màn sao lưu cho các bản cần có ở đó.', '保存先が別の場所に変わりました。以前のコピーは新しい保存先には未コピーとして扱います。必要なバックアップはバックアップ画面の「再コピー」を使ってください。'],
            $p . 'refused.not_configured' => ['No external destination is set.', 'Chưa cấu hình đích ngoài.', '外部の保存先が設定されていません。'],
            $p . 'refused.not_ready' => ['This backup cannot be copied (not verified, or already copied).', 'Bản này không chép được (chưa kiểm xong hoặc đã chép).', 'このバックアップはコピーできません（未検証、またはコピー済み）。'],
            $p . 'refused.already_running' => ['This backup is already being copied.', 'Bản này đang được chép.', 'このバックアップはコピー中です。'],
            $p . 'refused.not_found' => ['Backup not found.', 'Không tìm thấy bản sao lưu.', 'バックアップが見つかりません。'],
            $p . 'refused.incomplete' => ['This copy at the destination is incomplete.', 'Bản ở đích chưa hoàn chỉnh.', '保存先のコピーは不完全です。'],
            $p . 'refused.unknown_type' => ['Choose a type of destination.', 'Hãy chọn loại đích.', '保存先の種類を選択してください。'],
            $p . 'refused.unavailable' => ['This type of destination is not available on this site.', 'Loại đích này không khả dụng trên site này.', 'この種類の保存先はこのサイトでは利用できません。'],
            $p . 'refused.invalid' => ['Check the highlighted fields.', 'Hãy kiểm tra các ô được đánh dấu.', '強調表示された項目を確認してください。'],
            $p . 'problem.endpoint_invalid' => ['Not a valid https:// address (no user name or password in it).', 'Không phải địa chỉ https:// hợp lệ (không chứa tên đăng nhập hay mật khẩu).', '有効な https:// アドレスではありません（ユーザー名やパスワードは含めないでください）。'],
            $p . 'problem.endpoint_http' => ['Use https://. Plain http:// is accepted only for this machine or a private network.', 'Hãy dùng https://. http:// chỉ chấp nhận cho máy này hoặc mạng nội bộ.', 'https:// を使ってください。http:// はこのマシンかプライベートネットワークのみ許可されます。'],
            $p . 'problem.endpoint_blocked' => ['This address is not allowed (cloud metadata or link-local address).', 'Địa chỉ này không được phép (metadata của cloud hoặc địa chỉ link-local).', 'このアドレスは許可されていません（クラウドのメタデータまたはリンクローカル）。'],
            $p . 'problem.host_invalid' => ['Not a valid server name or IP address.', 'Không phải tên máy chủ hay địa chỉ IP hợp lệ.', '有効なサーバー名または IP アドレスではありません。'],
            $p . 'problem.host_blocked' => ['This server address is not allowed.', 'Địa chỉ máy chủ này không được phép.', 'このサーバーアドレスは許可されていません。'],
            $p . 'problem.invalid' => ['This value is not valid.', 'Giá trị không hợp lệ.', '値が無効です。'],
            $p . 'problem.required' => ['Required (type it again when the destination changed).', 'Bắt buộc (nhập lại khi đổi nơi nhận).', '必須です（保存先を変更した場合は再入力）。'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function report(): array
    {
        $p = 'admin.backup.offsite.';

        return [
            $p . 'report' => ['Last connection test', 'Lần kiểm tra kết nối gần nhất', '前回の接続テスト'],
            $p . 'right.write' => ['Write', 'Ghi', '書き込み'],
            $p . 'right.read' => ['Read', 'Đọc', '読み取り'],
            $p . 'right.list' => ['List', 'Liệt kê', '一覧'],
            $p . 'right.delete' => ['Delete', 'Xoá', '削除'],
            $p . 'yes' => ['yes', 'có', 'あり'],
            $p . 'no' => ['no', 'không', 'なし'],
            $p . 'missing' => ['This credential cannot be used: missing right(s) :rights.', 'Credential này chưa dùng được: thiếu quyền :rights.', 'この認証情報は使用できません。不足している権限: :rights。'],
            $p . 'recommend_write_only' => ['Works. Recommended: this credential can delete. Someone who takes over the site could delete the off-site copies too. Switch to a write-only credential and turn on Object Lock (see the guide below).', 'Dùng được. Khuyến nghị: credential này có quyền xoá — kẻ chiếm được site cũng xoá được bản ngoài. Hãy đổi sang credential chỉ-ghi và bật Object Lock (xem hướng dẫn bên dưới).', '使用できます。推奨: この認証情報には削除権限があります。サイトを乗っ取った者がオフサイトのコピーも削除できてしまいます。書き込み専用の認証情報に切り替え、Object Lock を有効にしてください（下のガイド参照）。'],
            $p . 'write_only_ok' => ['Works, and the credential cannot delete: someone who takes over the site cannot remove the off-site copies. Old copies are removed by the destination\'s lifecycle rule.', 'Dùng được, và credential không có quyền xoá: kẻ chiếm được site không xoá được bản ngoài. Bản cũ do quy tắc vòng đời của đích tự xoá.', '使用でき、認証情報に削除権限はありません。サイトを乗っ取られてもオフサイトのコピーは削除されません。古いコピーは保存先のライフサイクルルールで削除されます。'],
            $p . 'fingerprint' => ['Server key fingerprint (compare it with the one your host gives you):', 'Vân tay khoá máy chủ (đối chiếu với vân tay nhà cung cấp đưa cho bạn):', 'サーバーキーのフィンガープリント（提供元から伝えられた値と照合してください）:'],
            $p . 'fingerprint_pinned' => ['Pinned fingerprint:', 'Vân tay đã ghim:', '固定済みのフィンガープリント:'],
            $p . 'host_key_accept' => ['The server key changed on purpose — pin the new one', 'Khoá máy chủ được đổi có chủ đích — ghim khoá mới', 'サーバーキーは意図的に変更された — 新しいキーを固定'],
            $p . 'retention_skipped' => [':count old off-site copies were not deleted (the credential cannot delete). Let the destination\'s lifecycle rule remove them.', ':count bản cũ ở đích ngoài không bị xoá (credential không có quyền xoá). Hãy để quy tắc vòng đời của đích tự xoá.', ':count 件の古いオフサイトのコピーは削除されませんでした（認証情報に削除権限がありません）。保存先のライフサイクルルールで削除してください。'],
            $p . 'error.auth' => ['The destination refused the credential (wrong key, secret, password or private key).', 'Đích từ chối credential (sai key, secret, mật khẩu hoặc khoá riêng).', '保存先が認証情報を拒否しました（キー、シークレット、パスワード、秘密鍵の誤り）。'],
            $p . 'error.not_permitted' => ['The credential is not allowed to do this.', 'Credential không được phép làm việc này.', '認証情報にこの操作の権限がありません。'],
            $p . 'error.network' => ['The destination could not be reached. Try again later.', 'Không kết nối được tới đích. Hãy thử lại sau.', '保存先に接続できませんでした。後でもう一度お試しください。'],
            $p . 'error.host_key_changed' => ['The server presents a different key than the one pinned. Nothing was sent. If you did not change the server, someone may be intercepting the connection.', 'Máy chủ đưa ra khoá khác với khoá đã ghim. Chưa gửi gì đi. Nếu bạn không đổi máy chủ, có thể có kẻ đang chặn kết nối.', 'サーバーが固定済みとは異なるキーを提示しました。何も送信していません。サーバーを変更していない場合、接続が傍受されている可能性があります。'],
            $p . 'error.host_key_unknown' => ['The server key is not pinned yet: save and test the destination first.', 'Chưa ghim khoá máy chủ: hãy lưu và kiểm tra đích trước.', 'サーバーキーがまだ固定されていません。先に保存してテストしてください。'],
            $p . 'error.unavailable' => ['The library this destination needs is not installed.', 'Thư viện cho đích này chưa được cài.', 'この保存先に必要なライブラリがインストールされていません。'],
            $p . 'error.mismatch' => ['What the destination holds differs from what was sent.', 'Dữ liệu ở đích khác với dữ liệu đã gửi.', '保存先のデータが送信したデータと一致しません。'],
            $p . 'error.quota' => ['The destination is full or over its quota.', 'Đích đã đầy hoặc vượt hạn mức.', '保存先が満杯、または容量制限を超えています。'],
            $p . 'error.invalid_config' => ['The destination does not accept these settings (bucket, region or endpoint).', 'Đích không nhận cấu hình này (bucket, vùng hoặc endpoint).', '保存先がこの設定を受け付けません（バケット、リージョン、エンドポイント）。'],
            $p . 'error.not_configured' => ['No external destination is set.', 'Chưa cấu hình đích ngoài.', '外部の保存先が設定されていません。'],
            $p . 'error.not_found' => ['Not found at the destination (bucket or folder).', 'Không tìm thấy ở đích (bucket hoặc thư mục).', '保存先に見つかりません（バケットまたはフォルダー）。'],
            $p . 'error.error' => ['The destination returned an error.', 'Đích trả về lỗi.', '保存先がエラーを返しました。'],
            $p . 'partial.cleaned' => ['The half-copied files were removed from the destination.', 'Các file chép dở đã được xoá khỏi đích.', 'コピー途中のファイルは保存先から削除しました。'],
            $p . 'partial.lifecycle' => ['The half-copied files stay at the destination (the credential cannot delete): its lifecycle rule should remove them.', 'Các file chép dở còn ở đích (credential không có quyền xoá): hãy để quy tắc vòng đời của đích xoá.', 'コピー途中のファイルは保存先に残っています（削除権限なし）。ライフサイクルルールで削除してください。'],
            $p . 'partial.none' => ['Nothing was left half-copied.', 'Không có file chép dở.', 'コピー途中のファイルはありません。'],
            $p . 'partial.unknown' => ['Half-copied files may remain at the destination.', 'Có thể còn file chép dở ở đích.', 'コピー途中のファイルが保存先に残っている可能性があります。'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function guide(): array
    {
        $p = 'admin.backup.offsite.guide.';

        return [
            $p . 'title' => ['How to make off-site copies that nobody can delete from this site', 'Cách để bản ngoài không bị xoá từ site này', 'このサイトから削除できないオフサイトコピーの作り方'],
            $p . 'why' => ['Ransomware and attackers delete backups first. Give this site a credential that can write, read and list, but not delete or overwrite old copies, and turn on the provider\'s lock (Object Lock / retention). The backup feature works fully with such a credential.', 'Mã độc tống tiền và kẻ tấn công thường xoá bản sao lưu trước tiên. Hãy cấp cho site credential chỉ ghi, đọc và liệt kê — không xoá, không ghi đè bản cũ — và bật khoá bất biến của nhà cung cấp (Object Lock / retention). Chức năng sao lưu chạy đủ với credential như vậy.', 'ランサムウェアや攻撃者は最初にバックアップを削除します。このサイトには書き込み・読み取り・一覧のみ可能で、古いコピーの削除や上書きができない認証情報を与え、プロバイダーのロック（Object Lock / 保持）を有効にしてください。バックアップ機能はその認証情報で完全に動作します。'],
            $p . 'aws' => ['AWS S3: create the bucket with Object Lock (default retention, e.g. 30 days, "Compliance"), and an IAM user with only this policy (replace BUCKET):', 'AWS S3: tạo bucket có Object Lock (giữ mặc định, ví dụ 30 ngày, chế độ "Compliance"), và một IAM user chỉ có chính sách sau (thay BUCKET):', 'AWS S3: Object Lock を有効にしてバケットを作成し（既定の保持期間 例: 30 日、「コンプライアンス」）、次のポリシーのみを持つ IAM ユーザーを作成します（BUCKET を置き換え）:'],
            $p . 'r2' => ['Cloudflare R2: create an API token "Object Read & Write" limited to the bucket, and add a bucket lock rule (R2 → bucket → Settings → Bucket lock) so objects cannot be deleted before their age.', 'Cloudflare R2: tạo API token "Object Read & Write" giới hạn trong bucket, và thêm quy tắc khoá bucket (R2 → bucket → Settings → Bucket lock) để object không bị xoá trước hạn.', 'Cloudflare R2: バケットに限定した「Object Read & Write」API トークンを作成し、バケットロックルール（R2 → バケット → Settings → Bucket lock）を追加して期限前に削除できないようにします。'],
            $p . 'b2' => ['Backblaze B2: enable Object Lock on the bucket and create an application key with listFiles, readFiles and writeFiles only (without deleteFiles).', 'Backblaze B2: bật Object Lock cho bucket và tạo application key chỉ có listFiles, readFiles, writeFiles (không có deleteFiles).', 'Backblaze B2: バケットで Object Lock を有効にし、listFiles・readFiles・writeFiles のみ（deleteFiles なし）のアプリケーションキーを作成します。'],
            $p . 'sftp' => ['SFTP / NAS: use an account that can create files in its folder but not delete or rename them, and keep snapshots on the NAS.', 'SFTP / NAS: dùng tài khoản chỉ tạo được file trong thư mục của nó, không xoá hay đổi tên được, và bật snapshot trên NAS.', 'SFTP / NAS: フォルダー内でファイルを作成できても削除や名前変更ができないアカウントを使い、NAS のスナップショットを有効にしてください。'],
            $p . 'lifecycle' => ['Add a lifecycle rule that deletes objects older than your retention (e.g. 90 days): with a write-only credential, the site does not remove old copies itself.', 'Thêm quy tắc vòng đời xoá object cũ hơn thời gian giữ (ví dụ 90 ngày): với credential chỉ-ghi, site không tự xoá bản cũ.', '保持期間（例: 90 日）より古いオブジェクトを削除するライフサイクルルールを追加してください。書き込み専用の認証情報では、サイトは古いコピーを自分で削除しません。'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private static function screens(): array
    {
        $p = 'admin.backup.';

        return [
            $p . 'where.pending' => ['Local · copying off-site', 'Cục bộ · đang chép ra ngoài', 'ローカル · オフサイトへコピー中'],
            $p . 'where.failed' => ['Local · off-site copy failed', 'Cục bộ · chép ra ngoài lỗi', 'ローカル · オフサイトへのコピー失敗'],
            $p . 'job.upload' => ['Copying off-site', 'Đang chép ra ngoài', 'オフサイトへコピー中'],
            $p . 'offsite.push' => ['Copy off-site', 'Chép ra ngoài', 'オフサイトへコピー'],
            $p . 'offsite.pushed' => ['The copy to the external destination is queued.', 'Đã xếp lịch chép ra đích ngoài.', '外部の保存先へのコピーを予約しました。'],
            $p . 'warning.offsite_failed' => ['The last backup could not be copied to the external destination. The local copy is kept. Check the destination in the settings.', 'Bản gần nhất chưa chép được ra đích ngoài. Bản cục bộ vẫn giữ. Hãy kiểm tra đích ngoài trong cài đặt.', '最新のバックアップを外部の保存先にコピーできませんでした。ローカルのコピーは保持されています。設定で保存先を確認してください。'],
            $p . 'warning.offsite_unavailable' => ['The external destination cannot be used (missing library or changed server key). Backups stay local only.', 'Đích ngoài đang không dùng được (thiếu thư viện hoặc khoá máy chủ đã đổi). Bản sao lưu chỉ lưu cục bộ.', '外部の保存先を使用できません（ライブラリ不足、またはサーバーキーの変更）。バックアップはローカルのみです。'],
            $p . 'remote.title' => ['From the external destination', 'Từ đích ngoài', '外部の保存先から'],
            $p . 'remote.help' => ['Backups found at the external destination, including ones this site no longer lists. Listing needs no backup password; bringing one back does.', 'Các bản có ở đích ngoài, kể cả bản không còn trong danh mục của site. Liệt kê không cần mật khẩu sao lưu; kéo một bản về thì cần.', '外部の保存先にあるバックアップ（このサイトの一覧にないものを含む）。一覧表示にパスワードは不要ですが、取り戻すには必要です。'],
            $p . 'remote.list' => ['Look at the destination', 'Xem ở đích ngoài', '保存先を確認'],
            $p . 'remote.empty' => ['No backup of this site at the destination.', 'Không có bản nào của site này ở đích ngoài.', '保存先にこのサイトのバックアップはありません。'],
            $p . 'remote.fetch' => ['Bring back', 'Kéo về', '取り戻す'],
            $p . 'remote.incomplete' => ['incomplete', 'chưa hoàn chỉnh', '不完全'],
            $p . 'remote.in_catalogue' => ['already here', 'đã có ở site', 'サイトにあり'],
            $p . 'remote.started' => ['Bringing the backup back; it is checked before it can be used.', 'Đang kéo bản về; bản sẽ được kiểm trước khi dùng.', 'バックアップを取り戻しています。使用前に検証されます。'],
            $p . 'remote.not_configured' => ['No external destination is set (Settings).', 'Chưa cấu hình đích ngoài (Cài đặt).', '外部の保存先が設定されていません（設定）。'],
            $p . 'refused.fetch_mismatch' => ['A file at the destination does not match its listing. Nothing was brought in.', 'Một file ở đích không khớp với danh sách. Không có gì được đưa vào.', '保存先のファイルが一覧と一致しません。何も取り込んでいません。'],
            $p . 'mail.offsite_failed_title' => ['A backup was not copied off-site', 'Bản sao lưu chưa chép được ra ngoài', 'バックアップをオフサイトにコピーできませんでした'],
            $p . 'mail.offsite_failed_body' => ['Backup :archive of :site could not be copied to the external destination at :time (:error). The local copy is kept. :partial', 'Bản :archive của :site chưa chép được ra đích ngoài lúc :time (:error). Bản cục bộ vẫn giữ. :partial', ':site のバックアップ :archive を :time に外部の保存先へコピーできませんでした（:error）。ローカルのコピーは保持されています。:partial'],
        ];
    }
}
