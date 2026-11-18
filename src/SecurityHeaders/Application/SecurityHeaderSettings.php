<?php

namespace GP247\Core\SecurityHeaders\Application;

use GP247\Core\Models\AdminConfig;
use GP247\Core\SecurityHeaders\Domain\HeaderPolicy;
use GP247\Core\SecurityHeaders\Domain\HstsSetting;
use GP247\Core\SecurityHeaders\Domain\InvalidSecuritySetting;
use Illuminate\Support\Facades\DB;

/**
 * Site-wide security-header settings: eight `admin_config` rows in the GLOBAL tier
 * (store_id 0, group `global`), read through the per-request config memo so a page view
 * costs no extra query, written by the administrator-only settings screen.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-settings, US-security-headers-hsts-opt-in, US-security-headers-install-lifecycle
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
final class SecurityHeaderSettings
{
    public const CODE = 'security_headers';

    public const FRAME = 'security_header_frame';
    public const NOSNIFF = 'security_header_nosniff';
    public const REFERRER = 'security_header_referrer';
    public const PERMISSIONS = 'security_header_permissions';
    public const HSTS_ENABLED = 'security_hsts_enabled';
    public const HSTS_MAX_AGE = 'security_hsts_max_age';
    public const HSTS_SUBDOMAINS = 'security_hsts_subdomains';
    public const HSTS_PRELOAD = 'security_hsts_preload';

    /**
     * Value of every key on a fresh install (and on a site updating to this version).
     *
     * @return array<string, int>
     */
    public static function defaults(): array
    {
        return [
            self::FRAME => 1,
            self::NOSNIFF => 1,
            self::REFERRER => 1,
            self::PERMISSIONS => 1,
            self::HSTS_ENABLED => 0,
            self::HSTS_MAX_AGE => HstsSetting::STEPS[0],
            self::HSTS_SUBDOMAINS => 0,
            self::HSTS_PRELOAD => 0,
        ];
    }

    /**
     * Rows the install / update seeder writes with insertOrIgnore (an owner's value is kept).
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultRows(): array
    {
        $rows = [];
        $sort = 0;
        foreach (self::defaults() as $key => $value) {
            $rows[] = [
                'group' => 'global',
                'code' => self::CODE,
                'key' => $key,
                'value' => (string) $value,
                'sort' => $sort++,
                'detail' => 'admin.security_headers.' . $key,
                'store_id' => (string) GP247_STORE_ID_GLOBAL,
            ];
        }

        return $rows;
    }

    /**
     * Current values (stored value, or the default when the row is missing).
     *
     * @return array<string, int>
     */
    public static function values(): array
    {
        try {
            $stored = AdminConfig::getAllGlobal();
        } catch (\Throwable $e) {
            // WHY: before install (no table) the defaults apply — a header layer must not break the installer page.
            $stored = [];
        }
        $values = [];
        foreach (self::defaults() as $key => $default) {
            $values[$key] = array_key_exists($key, $stored) && is_numeric(trim((string) $stored[$key]))
                ? (int) trim((string) $stored[$key])
                : $default;
        }

        return $values;
    }

    /**
     * Policy built from the current values. A broken HSTS row (edited by hand) turns HSTS
     * off instead of failing every page.
     *
     * @param array<int, mixed> $grantedPermissions Features extensions asked to open.
     * @return HeaderPolicy
     */
    public static function policy(array $grantedPermissions = []): HeaderPolicy
    {
        $values = self::values();
        try {
            $hsts = self::hstsFrom($values);
        } catch (InvalidSecuritySetting $e) {
            $hsts = null;
        }

        return new HeaderPolicy(
            frame: (bool) $values[self::FRAME],
            nosniff: (bool) $values[self::NOSNIFF],
            referrer: (bool) $values[self::REFERRER],
            permissions: (bool) $values[self::PERMISSIONS],
            hsts: $hsts,
            grantedPermissions: $grantedPermissions,
        );
    }

    /**
     * Validate and store the settings the administrator chose.
     *
     * @param array<string, mixed> $input  Key => value from the settings screen (unknown keys are ignored).
     * @param string               $appUrl The site address (APP_URL) — HSTS needs https.
     * @return array<string, int> What was stored.
     * @throws InvalidSecuritySetting When HSTS is asked on a non-https site or breaks its rules.
     */
    public static function save(array $input, string $appUrl): array
    {
        // WHY start from the stored values: a key missing from $input keeps what the owner chose.
        $values = self::values();
        foreach (array_keys($values) as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = (int) (is_bool($input[$key]) ? $input[$key] : (is_numeric($input[$key]) ? $input[$key] : 0));
            }
        }
        if ($values[self::HSTS_ENABLED] && strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) !== 'https') {
            throw InvalidSecuritySetting::because('admin.security_headers.error_hsts_needs_https');
        }
        // WHY validate even when HSTS is off: the stored step must stay usable the day it is turned on.
        new HstsSetting($values[self::HSTS_MAX_AGE], (bool) $values[self::HSTS_SUBDOMAINS], (bool) $values[self::HSTS_PRELOAD]);

        $db = DB::connection(GP247_DB_CONNECTION);
        $db->transaction(function () use ($db, $values) {
            foreach (self::defaultRows() as $row) {
                $db->table(GP247_DB_PREFIX . 'admin_config')->updateOrInsert(
                    ['key' => $row['key'], 'store_id' => $row['store_id']],
                    ['group' => $row['group'], 'code' => $row['code'], 'value' => (string) $values[$row['key']], 'sort' => $row['sort'], 'detail' => $row['detail']]
                );
            }
        });
        AdminConfig::forgetMemo();

        return $values;
    }

    /**
     * HSTS parameters of the values, or null when HSTS is off.
     *
     * @param array<string, int> $values
     * @return HstsSetting|null
     * @throws InvalidSecuritySetting When the stored values break the HSTS rules.
     */
    private static function hstsFrom(array $values): ?HstsSetting
    {
        if (!$values[self::HSTS_ENABLED]) {
            return null;
        }

        return new HstsSetting($values[self::HSTS_MAX_AGE], (bool) $values[self::HSTS_SUBDOMAINS], (bool) $values[self::HSTS_PRELOAD]);
    }
}
