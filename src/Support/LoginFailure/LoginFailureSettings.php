<?php

namespace GP247\Core\Support\LoginFailure;

use GP247\Core\Support\Password\PasswordPolicyRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Which account groups have their failed sign-ins recorded: one site-wide
 * `admin_config` row (`login_failure_audiences`, comma-separated group keys,
 * administrators only by default). The groups to choose from are the account
 * groups of PasswordPolicyRegistry — their keys equal the auth guard names.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-log, US-admin-shell-login-failure-list
 * @aidlc-adr admin-shell_login-failure-log
 */
final class LoginFailureSettings
{
    public const KEY = 'login_failure_audiences';
    public const DEFAULT = 'admin';

    /**
     * Recorded group keys, read straight from the table so a change applies at once.
     *
     * @return list<string>
     */
    public static function audiences(): array
    {
        $value = DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', (string) GP247_STORE_ID_GLOBAL)
            ->where('key', self::KEY)
            ->value('value');

        return self::parse($value === null ? self::DEFAULT : (string) $value);
    }

    /**
     * Whether failures of $audience are recorded.
     *
     * @param string $audience
     * @return bool
     */
    public static function enabled(string $audience): bool
    {
        return in_array($audience, self::audiences(), true);
    }

    /**
     * Account groups the owner can choose from: key => label language code.
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        $groups = [];
        foreach (PasswordPolicyRegistry::all() as $audience) {
            $groups[$audience->key] = $audience->labelKey;
        }

        return $groups;
    }

    /**
     * Store the recorded groups; keys that are not registered groups are dropped.
     *
     * @param array<int, mixed> $audiences
     * @return list<string> What was stored.
     */
    public static function save(array $audiences): array
    {
        $allowed = array_keys(self::available());
        $keep = array_values(array_unique(array_filter(
            array_map(fn ($key) => is_scalar($key) ? (string) $key : '', $audiences),
            fn (string $key) => in_array($key, $allowed, true)
        )));

        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')->updateOrInsert(
            ['key' => self::KEY, 'store_id' => (string) GP247_STORE_ID_GLOBAL],
            ['group' => 'global', 'code' => 'login_failure', 'value' => implode(',', $keep), 'sort' => 0, 'detail' => 'admin.login_failures.audiences']
        );

        return $keep;
    }

    /**
     * The settings row a fresh install or an upgrade writes (administrators only).
     *
     * @return array<string, mixed>
     */
    public static function defaultRow(): array
    {
        return [
            'group' => 'global', 'code' => 'login_failure', 'key' => self::KEY, 'value' => self::DEFAULT,
            'sort' => 0, 'detail' => 'admin.login_failures.audiences', 'store_id' => (string) GP247_STORE_ID_GLOBAL,
        ];
    }

    /**
     * @param string $value
     * @return list<string>
     */
    private static function parse(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $key) => $key !== ''));
    }
}
