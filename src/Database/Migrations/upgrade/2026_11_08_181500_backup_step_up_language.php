<?php

use GP247\Core\Backup\Infrastructure\Install\BackupLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Running sites: clearer wording of the "Confirm it is you" box of the backup screens — it asks for
 * the admin sign-in password, not the backup password. No schema change. A fresh install gets the
 * text from the seeders.
 *
 * A text is replaced only while it is still the previous wording (a site that edited it keeps its
 * own); missing texts are added. Idempotent. down() leaves the texts.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-engine_core-module-layout
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> Previous wording: code => location => text. */
    private const PREVIOUS = [
        'admin.backup.step_up.help' => [
            'en' => 'Type your sign-in password again. It stays valid :minutes minutes in this browser.',
            'vi' => 'Nhập lại mật khẩu đăng nhập. Hiệu lực :minutes phút trên trình duyệt này.',
            'ja' => 'ログインパスワードを再入力してください。このブラウザで :minutes 分間有効です。',
        ],
        'admin.backup.step_up.password' => [
            'en' => 'Sign-in password',
            'vi' => 'Mật khẩu đăng nhập',
            'ja' => 'ログインパスワード',
        ],
    ];

    /**
     * @return void
     */
    public function up()
    {
        $connection = DB::connection(GP247_DB_CONNECTION);
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'languages')) {
            return;
        }
        foreach (BackupLanguage::rows() as $row) {
            $previous = self::PREVIOUS[$row['code']][$row['location']] ?? null;
            if ($previous !== null) {
                $connection->table(GP247_DB_PREFIX . 'languages')
                    ->where('code', $row['code'])->where('location', $row['location'])->where('text', $previous)
                    ->update(['text' => $row['text']]);
            }
        }
        $connection->table(GP247_DB_PREFIX . 'languages')->insertOrIgnore(BackupLanguage::rows());
    }

    /**
     * @return void
     */
    public function down()
    {
        // Intentionally empty: texts are harmless and may have been edited by the site.
    }
};
