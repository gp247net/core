<?php

namespace GP247\Core\PersonalData\Application;

use Illuminate\Support\Facades\DB;

/**
 * The site's secret for hashing erased customers' emails (HMAC-SHA256). Generated on first
 * use, stored encrypted in admin_config (secret row of the site-wide store), re-encrypted by
 * gp247:encryption-key-rotate like every admin_config secret (its value never changes), and
 * never taken back from a backup by a configuration restore.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erasure-ledger
 * @aidlc-adr personal-data-registry_erasure-ledger
 */
final class Pepper
{
    public const KEY = 'personal_data_pepper';
    public const CODE = 'personal_data';

    /** @var string|null Memo for the current request. */
    private static ?string $memo = null;

    /**
     * @return string The raw pepper (32 bytes as 64 hex characters).
     * @throws \RuntimeException When the stored pepper cannot be decrypted (encryption key changed).
     */
    public static function value(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $stored = $table->where('key', self::KEY)->where('store_id', GP247_STORE_ID_GLOBAL)->value('value');
        if ($stored === null || $stored === '') {
            // WHY insertOrIgnore + read back: two first erasures at once must agree on one pepper.
            $table->insertOrIgnore([
                'group' => null,
                'code' => self::CODE,
                'key' => self::KEY,
                'value' => gp247_secret_encrypt(bin2hex(random_bytes(32))),
                'security' => 1,
                'store_id' => GP247_STORE_ID_GLOBAL,
                'sort' => 0,
                'detail' => 'admin.personal_data.pepper',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stored = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
                ->where('key', self::KEY)->where('store_id', GP247_STORE_ID_GLOBAL)->value('value');
        }
        $plain = gp247_secret_decrypt((string) $stored, ['table' => 'admin_config', 'key' => self::KEY]);
        if (strlen($plain) !== 64) {
            throw new \RuntimeException('The personal data pepper cannot be read.');
        }

        return self::$memo = $plain;
    }

    /**
     * @param string $email Any form; normalised before hashing.
     * @return string HMAC-SHA256 hex.
     */
    public static function hashEmail(string $email): string
    {
        return hash_hmac('sha256', \GP247\Core\PersonalData\Domain\DataSubject::normalise($email), self::value());
    }

    /**
     * @return void Forget the memo (tests, key rotation in the same process).
     */
    public static function forget(): void
    {
        self::$memo = null;
    }
}
