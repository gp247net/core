<?php

namespace GP247\Core\Backup\Infrastructure\Store;

use GP247\Core\Backup\Application\KeyStore;
use GP247\Core\Backup\Application\KeyStoreConflict;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;

/**
 * Key ring rows in admin_config (global store, group/code "backup"). Reads the model
 * directly — not the cached gp247_config() map, which would go stale right after a write.
 * The signing key row is flagged security=1 and so encrypted at rest (enc:v2) and rotated by
 * gp247:encryption-key-rotate. Writes run in one transaction with the expected rows locked;
 * the unique (key, store_id) index stops a concurrent first set-up as well.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption, backup-engine_tamper-evidence
 */
final class AdminConfigKeyStore implements KeyStore
{
    public const GROUP = 'backup';

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?string
    {
        $row = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', $key)->first();

        // The value accessor decrypts secret rows ('' when it cannot).
        return $row === null ? null : (string) $row->value;
    }

    /**
     * {@inheritdoc}
     */
    public function putMany(#[\SensitiveParameter] array $values, array $secretKeys = [], array $expect = []): void
    {
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($values, $secretKeys, $expect) {
            foreach ($expect as $key => $expected) {
                $row = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', $key)->lockForUpdate()->first();
                $current = $row === null ? null : (string) $row->value;
                if ($current !== $expected) {
                    throw new KeyStoreConflict('Backup key ring changed concurrently.');
                }
            }
            foreach ($values as $key => $value) {
                AdminConfig::setConfigValue(self::GROUP, $key, GP247_STORE_ID_GLOBAL, $value, in_array($key, $secretKeys, true) ? 1 : 0);
            }
        });
    }
}
