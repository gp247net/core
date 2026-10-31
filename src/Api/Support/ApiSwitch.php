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
 * A package or plugin may register a switch of its own for part of a group
 * (`gp247-config.api.switches`): it shows with the others on the API screen, has its own
 * default when the site never chose, only matters while its parent group is open, and
 * refuses with its own status and code (ADR api-commerce_switch-registry).
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
        foreach (self::registered() as $key => $switch) {
            $states[$key] = array_key_exists($key, $config)
                ? !in_array(trim((string) $config[$key]), ['0', ''], true)
                : $switch['default'];
        }
        return $states;
    }

    /**
     * Switches registered by packages and plugins, normalised.
     *
     * @return array<string, array{label: string, parent: string, prefix: string, default: bool, status: int, code: string, message: string}>
     */
    public static function registered(): array
    {
        $switches = [];
        foreach ((array) config('gp247-config.api.switches', []) as $key => $switch) {
            $prefix = trim((string) ($switch['prefix'] ?? ''), '/');
            if (in_array($key, self::KEYS, true) || $prefix === '') {
                continue;
            }
            $switches[(string) $key] = [
                'label'   => (string) ($switch['label'] ?? $key),
                'parent'  => (string) ($switch['parent'] ?? ''),
                'prefix'  => $prefix,
                'default' => (bool) ($switch['default'] ?? true),
                'status'  => (int) ($switch['status'] ?? 404),
                'code'    => (string) ($switch['code'] ?? 'api_disabled'),
                'message' => (string) ($switch['message'] ?? 'This API is disabled on this site'),
            ];
        }

        return $switches;
    }

    /**
     * Every switch key: the built-in ones, then the registered ones.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_merge(self::KEYS, array_keys(self::registered()));
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
        return self::refusal($uri) === null;
    }

    /**
     * Why a request to this uri is refused, or null when it may reach the API: the master
     * switch, then the group switch, then a registered switch whose prefix covers the uri.
     *
     * @param string $uri Request path.
     * @return array{status: int, code: string, msg: string}|null
     */
    public static function refusal(string $uri): ?array
    {
        $closed = ['status' => 404, 'code' => 'api_disabled', 'msg' => 'This API is disabled on this site'];
        $states = self::states();
        if (!$states[self::MASTER]) {
            return $closed;
        }
        $group = self::groupOf($uri);
        if ($group !== null && !$states[$group]) {
            return $closed;
        }
        $uri = trim($uri, '/');
        foreach (self::registered() as $key => $switch) {
            if (($uri === $switch['prefix'] || Str::startsWith($uri, $switch['prefix'] . '/')) && !$states[$key]) {
                return ['status' => $switch['status'], 'code' => $switch['code'], 'msg' => $switch['message']];
            }
        }

        return null;
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
