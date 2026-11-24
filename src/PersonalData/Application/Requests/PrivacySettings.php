<?php

namespace GP247\Core\PersonalData\Application\Requests;

use Illuminate\Support\Facades\DB;

/**
 * The site-wide personal data settings (one policy for every store): how long an erasure waits
 * so the customer can change their mind, and after how many days an unhandled request is
 * overdue. Read from admin_config (group personal_data, store GLOBAL).
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-settings
 * @aidlc-adr personal-data-requests_access-and-settings
 */
final class PrivacySettings
{
    public const GROUP = 'personal_data';
    public const WAIT_DAYS = 'personal_data_erase_wait_days';
    public const OVERDUE_DAYS = 'personal_data_overdue_days';
    public const NOT_CUSTOMER_TABLES = 'personal_data_not_customer_tables';

    public const DEFAULT_WAIT_DAYS = 3;
    public const DEFAULT_OVERDUE_DAYS = 20;
    public const MAX_WAIT_DAYS = 30;
    public const MAX_OVERDUE_DAYS = 365;

    /**
     * @return int Days an erasure waits before it runs (0–30).
     */
    public static function waitDays(): int
    {
        return self::clampWait(self::read(self::WAIT_DAYS, (string) self::DEFAULT_WAIT_DAYS));
    }

    /**
     * @return int Days after which an unhandled request is overdue (1–365).
     */
    public static function overdueDays(): int
    {
        return self::clampOverdue(self::read(self::OVERDUE_DAYS, (string) self::DEFAULT_OVERDUE_DAYS));
    }

    /**
     * @param mixed $value
     * @return int
     */
    public static function clampWait(mixed $value): int
    {
        return max(0, min(self::MAX_WAIT_DAYS, (int) (is_numeric($value) ? $value : self::DEFAULT_WAIT_DAYS)));
    }

    /**
     * @param mixed $value
     * @return int
     */
    public static function clampOverdue(mixed $value): int
    {
        return max(1, min(self::MAX_OVERDUE_DAYS, (int) (is_numeric($value) ? $value : self::DEFAULT_OVERDUE_DAYS)));
    }

    /**
     * @return list<string> Tables an administrator marked as holding no customer data.
     */
    public static function notCustomerTables(): array
    {
        $decoded = json_decode(self::read(self::NOT_CUSTOMER_TABLES, '[]'), true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), fn ($t) => $t !== '')) : [];
    }

    /**
     * @param list<string> $tables
     * @return void
     */
    public static function saveNotCustomerTables(array $tables): void
    {
        $value = (string) json_encode(array_values(array_unique($tables)), JSON_UNESCAPED_SLASHES);
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')->updateOrInsert(
            ['key' => self::NOT_CUSTOMER_TABLES, 'store_id' => (string) GP247_STORE_ID_GLOBAL],
            ['group' => self::GROUP, 'code' => self::GROUP, 'value' => $value, 'security' => 0, 'sort' => 9, 'detail' => 'admin.personal_data.settings.not_customer_tables'],
        );
    }

    /**
     * @return list<array<string, mixed>> admin_config rows with the defaults (inserted only when missing).
     */
    public static function defaultRows(): array
    {
        $row = fn (string $key, string $value, int $sort) => [
            'group' => self::GROUP, 'code' => self::GROUP, 'key' => $key, 'value' => $value, 'security' => 0,
            'store_id' => (string) GP247_STORE_ID_GLOBAL, 'sort' => $sort,
            'detail' => 'admin.personal_data.settings.' . substr($key, strlen('personal_data_')),
        ];

        return [
            $row(self::WAIT_DAYS, (string) self::DEFAULT_WAIT_DAYS, 1),
            $row(self::OVERDUE_DAYS, (string) self::DEFAULT_OVERDUE_DAYS, 2),
            $row(self::NOT_CUSTOMER_TABLES, '[]', 9),
        ];
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    private static function read(string $key, string $default): string
    {
        try {
            $value = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
                ->where('key', $key)->where('store_id', (string) GP247_STORE_ID_GLOBAL)->value('value');
        } catch (\Throwable $e) {
            return $default;
        }

        return $value === null ? $default : (string) $value;
    }
}
