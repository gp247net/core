<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent upgrade: encrypt the KNOWN plaintext secrets already sitting in admin_config,
 * and set their security flag (ADR compat-foundation_config-secret-at-rest).
 *
 * WHY a one-off migration on top of lazy-on-write: without it, a secret already stored
 * plaintext would stay plaintext until the admin happens to re-save it. This converts the
 * secrets we ship/know about now; anything written afterwards is encrypted by the write
 * choke. Runs AFTER the value->TEXT widening migration (same batch, earlier timestamp).
 *
 * Idempotent: rows already carrying the envelope prefix are skipped, so re-running is a
 * no-op. Empty values are left untouched (nothing to protect).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-config-secret-at-rest
 * @aidlc-adr compat-foundation_config-secret-at-rest
 */
return new class extends Migration
{
    /**
     * Encrypt known secrets in place and flag them.
     *
     * @return void
     */
    public function up()
    {
        if (!function_exists('gp247_secret_encrypt')) {
            return;
        }

        $table = GP247_DB_PREFIX . 'admin_config';
        $connection = DB::connection(GP247_DB_CONNECTION);

        // Match the known secret keys: exact smtp_password, any *_secrect_key /
        // *_client_secret (GoogleCaptcha, LoginSocial), and the whole ExtensionLicense group.
        $rows = $connection->table($table)
            ->where(function ($q) {
                $q->where('key', 'smtp_password')
                    ->orWhere('key', 'like', '%\_secrect\_key')
                    ->orWhere('key', 'like', '%\_client\_secret')
                    ->orWhere('group', 'ExtensionLicense');
            })
            ->get();

        foreach ($rows as $row) {
            $value = (string) ($row->value ?? '');
            if ($value === '' || gp247_secret_is_encrypted($value)) {
                // Still make sure the flag is set for a non-empty already-encrypted row.
                if ($value !== '' && (int) ($row->security ?? 0) !== 1) {
                    $connection->table($table)->where('id', $row->id)->update(['security' => 1]);
                }
                continue;
            }

            $connection->table($table)->where('id', $row->id)->update([
                'value' => gp247_secret_encrypt($value),
                'security' => 1,
            ]);
        }
    }

    /**
     * No rollback: decrypting back to plaintext would defeat the purpose and risks losing
     * a value that can no longer be decrypted. Left intentionally empty.
     *
     * @return void
     */
    public function down()
    {
        // Intentionally empty — see method doc.
    }
};
