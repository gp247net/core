<?php

namespace GP247\Core\Api\Support;

use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Str;

/**
 * The site owner's REST API switches, read by the `api.enabled` gate and the API
 * management screen: a master switch plus one per version and audience (v1/v2 ×
 * core/front), stored in the global admin_config. The env GP247_API_MODE stays the
 * server ceiling (no route is registered when it is off). A missing key counts as on,
 * so a site that has not run gp247:update keeps its API.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-switch
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiSwitch
{
    public const MASTER = 'api_enabled';

    /** @var string[] Every switch key, master first. */
    public const KEYS = ['api_enabled', 'api_v1_core', 'api_v1_front', 'api_v2_core', 'api_v2_front'];

    /**
     * Whether the server configuration lets the API exist at all (GP247_API_MODE).
     *
     * @return bool
     */
    public static function serverEnabled(): bool
    {
        return (bool) config('gp247-config.env.GP247_API_MODE');
    }

    /**
     * Current value of every switch; a missing key counts as on.
     *
     * WHY AdminConfig::getAllGlobal() and not gp247_config_global(): the helper swallows
     * a database error and would read every switch as on (fail-open).
     *
     * @return array<string, bool>
     */
    public static function states(): array
    {
        $config = AdminConfig::getAllGlobal();
        $states = [];
        foreach (self::KEYS as $key) {
            $states[$key] = !array_key_exists($key, $config) || !in_array(trim((string) $config[$key]), ['0', ''], true);
        }
        return $states;
    }

    /**
     * Group switch that controls a route uri, by the GP247 API prefixes; null for a route
     * outside them (a plugin with its own prefix follows the master switch only).
     *
     * @param string $uri Route uri or request path, without a leading slash.
     * @return string|null One of KEYS except the master.
     */
    public static function groupOf(string $uri): ?string
    {
        $uri = trim($uri, '/');
        foreach (self::prefixes() as $key => $prefix) {
            if ($uri === $prefix || Str::startsWith($uri, $prefix . '/')) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Whether a request to this uri may reach the API.
     *
     * @param string $uri Request path.
     * @return bool
     */
    public static function allows(string $uri): bool
    {
        $states = self::states();
        if (!$states[self::MASTER]) {
            return false;
        }
        $group = self::groupOf($uri);
        return $group === null || $states[$group];
    }

    /**
     * Group switch key => route prefix.
     *
     * @return array<string, string>
     */
    public static function prefixes(): array
    {
        return [
            'api_v1_core'  => trim(GP247_API_CORE_PREFIX, '/'),
            'api_v1_front' => trim(GP247_API_FRONT_PREFIX, '/'),
            'api_v2_core'  => trim(GP247_API_V2_CORE_PREFIX, '/'),
            'api_v2_front' => trim(GP247_API_V2_FRONT_PREFIX, '/'),
        ];
    }
}
