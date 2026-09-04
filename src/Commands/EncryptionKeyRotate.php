<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypt every stored secret with the CURRENT active encryption key.
 *
 * The safe way to change the encryption key without losing data:
 *   1. keep the old key readable — put it in GP247_ENCRYPTION_PREVIOUS_KEYS;
 *   2. set the new key in GP247_ENCRYPTION_KEY;
 *   3. `php artisan config:clear`;
 *   4. `php artisan gp247:encryption-key-rotate` — this command reads each secret with
 *      the old key (still available) and rewrites it with the new key;
 *   5. once it reports 0 remaining old rows, GP247_ENCRYPTION_PREVIOUS_KEYS can be dropped.
 *
 * Scope: every table/column registered in config('gp247-config.security.encrypted_columns')
 * — admin_config and any plugin that appended its own. Idempotent: rows already written
 * with the active key are skipped, so re-running is a no-op. --dry-run counts without writing.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-config-secret-at-rest
 * @aidlc-adr compat-foundation_config-secret-at-rest
 */
class EncryptionKeyRotate extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:encryption-key-rotate {--json} {--dry-run : Count rows to convert without writing}';

    /** @var string */
    protected $description = 'Re-encrypt stored secrets with the current active encryption key';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $columns = (array) config('gp247-config.security.encrypted_columns', []);

        $results = [];
        $totalConverted = 0;
        $totalFailed = 0;

        foreach ($columns as $table => $cols) {
            foreach ((array) $cols as $column) {
                $stat = $this->rotateColumn((string) $table, (string) $column, $dryRun);
                $results[] = $stat;
                $totalConverted += $stat['converted'];
                $totalFailed += $stat['failed'];

                if (!$this->isJson()) {
                    $this->line(sprintf(
                        '  %s.%s: %d converted, %d already current, %d undecryptable',
                        $stat['table'],
                        $stat['column'],
                        $stat['converted'],
                        $stat['current'],
                        $stat['failed']
                    ));
                }
            }
        }

        if ($totalFailed > 0) {
            return $this->respondFailure(
                'rotation_incomplete',
                $totalFailed . ' secret(s) could not be decrypted — restore the previous key (GP247_ENCRYPTION_PREVIOUS_KEYS / APP_PREVIOUS_KEYS) before rotating',
                ['dry_run' => $dryRun, 'converted' => $totalConverted, 'failed' => $totalFailed, 'columns' => $results]
            );
        }

        return $this->respondSuccess(['dry_run' => $dryRun, 'converted' => $totalConverted, 'columns' => $results]);
    }

    /**
     * Re-encrypt the secrets in one table column.
     *
     * @param string $table  Unprefixed table name.
     * @param string $column Column holding the enveloped secret.
     * @param bool   $dryRun Count only, do not write.
     * @return array{table: string, column: string, converted: int, current: int, failed: int}
     */
    private function rotateColumn(string $table, string $column, bool $dryRun): array
    {
        $fullTable = GP247_DB_PREFIX . $table;
        $connection = DB::connection(GP247_DB_CONNECTION);

        $converted = 0;
        $current = 0;
        $failed = 0;

        // Only enveloped rows; a table may legitimately mix plaintext + secret (admin_config).
        $rows = $connection->table($fullTable)
            ->where($column, 'like', 'enc:%')
            ->get(['id', $column]);

        foreach ($rows as $row) {
            $value = (string) ($row->{$column} ?? '');
            if (!gp247_secret_needs_rotation($value)) {
                $current++;
                continue;
            }

            $plain = gp247_secret_decrypt($value);
            if ($plain === '') {
                // Fail-safe decrypt returns '' — do NOT overwrite (would destroy the row).
                $failed++;
                continue;
            }

            if (!$dryRun) {
                $connection->table($fullTable)
                    ->where('id', $row->id)
                    ->update([$column => gp247_secret_encrypt($plain)]);
            }
            $converted++;
        }

        return compact('table', 'column', 'converted', 'current', 'failed');
    }
}
