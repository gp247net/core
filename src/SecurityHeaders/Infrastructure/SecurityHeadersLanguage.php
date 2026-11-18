<?php

namespace GP247\Core\SecurityHeaders\Infrastructure;

/**
 * Language rows (en / vi / ja) of the "Security headers" screen — read by the core
 * language seeder, which `gp247:update` re-runs with insertOrIgnore (no upgrade migration).
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-install-lifecycle, US-security-headers-settings, US-security-headers-hsts-opt-in
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
final class SecurityHeadersLanguage
{
    /**
     * Texts keyed by language code, then locale.
     *
     * @return array<string, array{en: string, vi: string, ja: string}>
     */
    public static function texts(): array
    {
        return [
            'admin.menu_titles.security_headers' => ['en' => 'Security headers', 'vi' => 'Header bảo mật', 'ja' => 'セキュリティヘッダー'],
            'admin.security_headers.title' => ['en' => 'Security headers', 'vi' => 'Header bảo mật', 'ja' => 'セキュリティヘッダー'],
            'admin.security_headers.subtitle' => [
                'en' => 'Instructions the site sends to every visitor\'s browser to block common attacks. Applies to the whole site. Administrators only.',
                'vi' => 'Chỉ dẫn site gửi kèm mỗi trang để trình duyệt chặn các kiểu tấn công phổ biến. Áp dụng cho toàn hệ thống. Chỉ quản trị viên cấp cao.',
                'ja' => 'よくある攻撃を防ぐため、サイトが訪問者のブラウザーへ送る指示です。サイト全体に適用されます。管理者のみ。',
            ],
            'admin.security_headers.frame' => ['en' => 'Block embedding in other sites (X-Frame-Options)', 'vi' => 'Chặn nhúng site vào trang khác (X-Frame-Options)', 'ja' => '他サイトへの埋め込みを禁止 (X-Frame-Options)'],
            'admin.security_headers.frame_help' => [
                'en' => 'Stops other websites from showing your pages inside a frame to trick visitors into clicks. Turn off only if your site must be embedded on another domain.',
                'vi' => 'Ngăn site khác nhúng trang của bạn vào khung để lừa khách bấm. Chỉ tắt khi site cần được nhúng ở một tên miền khác.',
                'ja' => '他サイトがフレーム内にページを表示して訪問者にクリックさせる手口を防ぎます。別ドメインへ埋め込む必要がある場合のみオフにします。',
            ],
            'admin.security_headers.nosniff' => ['en' => 'Block file-type guessing (X-Content-Type-Options)', 'vi' => 'Chặn đoán kiểu file (X-Content-Type-Options)', 'ja' => 'ファイル形式の推測を禁止 (X-Content-Type-Options)'],
            'admin.security_headers.nosniff_help' => [
                'en' => 'Browsers treat each file only as the type the site declares, so an uploaded file cannot run as a script.',
                'vi' => 'Trình duyệt chỉ xử lý file đúng kiểu site khai báo, nên file tải lên không chạy được như mã lệnh.',
                'ja' => 'ブラウザーはサイトが宣言した形式でのみファイルを扱うため、アップロードされたファイルがスクリプトとして実行されません。',
            ],
            'admin.security_headers.referrer' => ['en' => 'Limit address sharing (Referrer-Policy)', 'vi' => 'Hạn chế chia sẻ địa chỉ trang (Referrer-Policy)', 'ja' => '参照元の共有を制限 (Referrer-Policy)'],
            'admin.security_headers.referrer_help' => [
                'en' => 'Other sites only see your domain, not the full page address. Analytics still sees where visits come from.',
                'vi' => 'Site khác chỉ thấy tên miền của bạn, không thấy đủ đường dẫn trang. Công cụ đo lường vẫn biết khách đến từ đâu.',
                'ja' => '他サイトにはドメインのみが伝わり、ページの完全なアドレスは伝わりません。アクセス解析は流入元を引き続き把握できます。',
            ],
            'admin.security_headers.permissions' => ['en' => 'Turn off camera, microphone and location (Permissions-Policy)', 'vi' => 'Tắt camera, micro, định vị (Permissions-Policy)', 'ja' => 'カメラ・マイク・位置情報を無効化 (Permissions-Policy)'],
            'admin.security_headers.permissions_help' => [
                'en' => 'No script on your pages can ask for these, unless an installed extension needs one.',
                'vi' => 'Không đoạn mã nào trên trang xin được các quyền này, trừ khi một extension đã cài cần dùng.',
                'ja' => 'インストール済みの拡張機能が必要とする場合を除き、ページ上のスクリプトはこれらを要求できません。',
            ],
            'admin.security_headers.permissions_granted' => ['en' => 'Opened for installed extensions: :features', 'vi' => 'Đang mở cho extension đã cài: :features', 'ja' => 'インストール済みの拡張機能のために許可: :features'],
            'admin.security_headers.hsts' => ['en' => 'HTTPS only (HSTS)', 'vi' => 'Chỉ dùng HTTPS (HSTS)', 'ja' => 'HTTPS のみ (HSTS)'],
            'admin.security_headers.hsts_help' => [
                'en' => 'Browsers that visited once will only open the site over HTTPS. Turn on only when HTTPS works on every page.',
                'vi' => 'Trình duyệt đã ghé một lần sẽ chỉ mở site bằng HTTPS. Chỉ bật khi HTTPS đã chạy ổn trên mọi trang.',
                'ja' => '一度訪れたブラウザーはサイトを HTTPS でのみ開きます。すべてのページで HTTPS が動作している場合のみ有効にします。',
            ],
            'admin.security_headers.hsts_enabled' => ['en' => 'Turn on HSTS', 'vi' => 'Bật HSTS', 'ja' => 'HSTS を有効にする'],
            'admin.security_headers.hsts_max_age' => ['en' => 'Browsers remember it for', 'vi' => 'Trình duyệt ghi nhớ trong', 'ja' => 'ブラウザーが記憶する期間'],
            'admin.security_headers.hsts_subdomains' => ['en' => 'Also every subdomain (includeSubDomains)', 'vi' => 'Áp cả mọi tên miền con (includeSubDomains)', 'ja' => 'すべてのサブドメインにも適用 (includeSubDomains)'],
            'admin.security_headers.hsts_preload' => ['en' => 'Ask to be built into browsers (preload)', 'vi' => 'Xin đưa vào danh sách cài sẵn của trình duyệt (preload)', 'ja' => 'ブラウザー組み込みリストへの登録を希望 (preload)'],
            'admin.security_headers.hsts_warning' => [
                'en' => 'Start short and raise the period step by step. Turning HSTS off later does not reach browsers that already remember it.',
                'vi' => 'Bắt đầu ngắn rồi tăng dần từng bậc. Tắt HSTS sau này không gỡ được ở trình duyệt đã ghi nhớ.',
                'ja' => '短い期間から始め、段階的に延ばしてください。後で HSTS を無効にしても、既に記憶したブラウザーには反映されません。',
            ],
            'admin.security_headers.hsts_confirm' => [
                'en' => 'Browsers will refuse plain HTTP for this site for the chosen period, and this cannot be taken back from browsers that already visited. Continue?',
                'vi' => 'Trình duyệt sẽ từ chối mở site bằng HTTP trong khoảng thời gian đã chọn, và không gỡ được ở trình duyệt đã ghé. Tiếp tục?',
                'ja' => '選択した期間、ブラウザーはこのサイトを HTTP で開かなくなり、既に訪れたブラウザーからは取り消せません。続行しますか？',
            ],
            'admin.security_headers.step_300' => ['en' => '5 minutes', 'vi' => '5 phút', 'ja' => '5 分'],
            'admin.security_headers.step_86400' => ['en' => '1 day', 'vi' => '1 ngày', 'ja' => '1 日'],
            'admin.security_headers.step_604800' => ['en' => '1 week', 'vi' => '1 tuần', 'ja' => '1 週間'],
            'admin.security_headers.step_2592000' => ['en' => '1 month', 'vi' => '1 tháng', 'ja' => '1 か月'],
            'admin.security_headers.step_15552000' => ['en' => '6 months', 'vi' => '6 tháng', 'ja' => '6 か月'],
            'admin.security_headers.step_31536000' => ['en' => '1 year', 'vi' => '1 năm', 'ja' => '1 年'],
            'admin.security_headers.step_63072000' => ['en' => '2 years', 'vi' => '2 năm', 'ja' => '2 年'],
            'admin.security_headers.error_hsts_needs_https' => [
                'en' => 'HSTS needs the site address (APP_URL) to start with https://.',
                'vi' => 'HSTS cần địa chỉ site (APP_URL) bắt đầu bằng https://.',
                'ja' => 'HSTS にはサイトのアドレス (APP_URL) が https:// で始まる必要があります。',
            ],
            'admin.security_headers.error_hsts_step' => ['en' => 'Choose a period from the list.', 'vi' => 'Chọn một khoảng thời gian trong danh sách.', 'ja' => 'リストから期間を選択してください。'],
            'admin.security_headers.error_hsts_preload' => [
                'en' => 'Preload needs "Also every subdomain" and a period of at least 1 year.',
                'vi' => 'Preload cần bật "Áp cả mọi tên miền con" và thời gian từ 1 năm trở lên.',
                'ja' => 'preload には「すべてのサブドメインにも適用」と 1 年以上の期間が必要です。',
            ],
            'admin.security_headers.emergency_off' => [
                'en' => 'GP247_SECURITY_HEADERS=0 is set in the environment: the site sends no security header, whatever is chosen here.',
                'vi' => 'Môi trường đang đặt GP247_SECURITY_HEADERS=0: site không gửi header bảo mật nào, bất kể lựa chọn ở đây.',
                'ja' => '環境変数 GP247_SECURITY_HEADERS=0 が設定されています。ここでの設定に関係なく、サイトはセキュリティヘッダーを送信しません。',
            ],
            'admin.alerts.security_headers_ok' => ['en' => 'Security headers are sent', 'vi' => 'Đang gửi header bảo mật', 'ja' => 'セキュリティヘッダーを送信しています'],
            'admin.alerts.security_headers_off' => [
                'en' => 'Some security headers are switched off: :headers.',
                'vi' => 'Một số header bảo mật đang tắt: :headers.',
                'ja' => '一部のセキュリティヘッダーが無効です: :headers。',
            ],
            'admin.alerts.security_headers_emergency_off' => [
                'en' => 'GP247_SECURITY_HEADERS=0 is set: the site sends no security header. Remove it once the problem is fixed.',
                'vi' => 'Đang đặt GP247_SECURITY_HEADERS=0: site không gửi header bảo mật nào. Gỡ biến này khi đã xử lý xong sự cố.',
                'ja' => 'GP247_SECURITY_HEADERS=0 が設定されています。サイトはセキュリティヘッダーを送信しません。問題が解決したら削除してください。',
            ],
            'admin.alerts.security_headers_hsts_without_https' => [
                'en' => 'HSTS is on but the site address (APP_URL) is not https:// — visitors may be locked out. Turn HSTS off or fix APP_URL.',
                'vi' => 'HSTS đang bật nhưng địa chỉ site (APP_URL) không phải https:// — khách có thể không vào được. Tắt HSTS hoặc sửa APP_URL.',
                'ja' => 'HSTS が有効ですが、サイトのアドレス (APP_URL) が https:// ではありません。訪問者がアクセスできなくなる恐れがあります。HSTS を無効にするか APP_URL を修正してください。',
            ],
            'admin.alerts.fix_security_headers' => ['en' => 'Open Security headers', 'vi' => 'Mở Header bảo mật', 'ja' => 'セキュリティヘッダーを開く'],
            'admin.security_headers.save' => ['en' => 'Save', 'vi' => 'Lưu', 'ja' => '保存'],
            'admin.security_headers.saved' => ['en' => 'Saved', 'vi' => 'Đã lưu', 'ja' => '保存しました'],
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
                $rows[] = [
                    'code' => $code,
                    'text' => $text,
                    'position' => substr($code, 0, (int) strrpos($code, '.')),
                    'location' => $location,
                ];
            }
        }

        return $rows;
    }
}
