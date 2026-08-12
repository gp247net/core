<?php

use \Illuminate\Support\Facades\Cache;

if (!function_exists('gp247_cache_version') && !in_array('gp247_cache_version', config('gp247_functions_except', []))) {
    /**
     * Current invalidation version for a cache group.
     *
     * WHY: the cache driver is `database` (NFR-SCAL-001/AVAIL-001) which has no
     * wildcard-forget/tag support, so per-group cache keys (that fan out across
     * store x locale) cannot be cleared by pattern. Consumers instead embed this
     * version in their key (`..._v{ver}`); clearing bumps the version so every
     * old-version key becomes unreachable and is reclaimed by its TTL. This mirrors
     * the existing SeoController::cacheVersion() pattern (NFR-MAINT consistency).
     *
     * The version is stored globally per group (not per store) — one bump covers
     * every store x locale variant without enumerating AdminStore/languages.
     *
     * @param string $group Cache group name (e.g. 'category', 'page').
     * @return int Current version (>= 1); defaults to 1 when never bumped.
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-AUI-cache-config-hardening
     * @aidlc-adr admin-shell_cache-config-hardening
     */
    function gp247_cache_version($group)
    {
        return (int) Cache::get('gp247_cache_ver_' . $group, 1);
    }
}

if (!function_exists('gp247_cache_bump') && !in_array('gp247_cache_bump', config('gp247_functions_except', []))) {
    /**
     * Invalidate a whole cache group by incrementing its version.
     *
     * WHY: see gp247_cache_version(). One bump reachably clears every store x locale
     * key in the group at once (old keys age out via TTL) without needing a wildcard
     * forget the `database` driver does not support.
     *
     * @param string $group Cache group name (e.g. 'category', 'page').
     * @return int The new version.
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-AUI-cache-config-hardening
     * @aidlc-adr admin-shell_cache-config-hardening
     */
    function gp247_cache_bump($group)
    {
        $next = gp247_cache_version($group) + 1;
        // Version marker must outlive the data it guards, so store it without TTL.
        Cache::forever('gp247_cache_ver_' . $group, $next);
        return $next;
    }
}

if (!function_exists('gp247_cache_clear') && !in_array('gp247_cache_clear', config('gp247_functions_except', []))) {
    /**
     * Clear a GP247 cache group (or all known GP247 groups).
     *
     * Version-bumped groups (`cache_category`, `cache_page`) are invalidated by
     * bumping their version so every store x locale variant is dropped at once.
     * Flat-keyed groups (`cache_country`) are forgotten directly. `cache_all`
     * clears only the known GP247 groups — it no longer calls Cache::flush(),
     * which used to wipe unrelated caches such as the SEO sitemap version and the
     * extension-update manager (RISK-OPS-cache-global-flush).
     *
     * @param string      $typeCache One of: 'cache_all', 'cache_category',
     *                               'cache_page', 'cache_country'. Any other value
     *                               is treated as a flat cache key and forgotten.
     * @param int|null    $storeId   Unused for version-bumped groups (versions are
     *                               global per group); kept for signature stability.
     * @return array{error:int,msg:string,action:string}
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-AUI-cache-config-hardening
     * @aidlc-adr admin-shell_cache-config-hardening
     */
    function gp247_cache_clear($typeCache = 'cache_all', $storeId = null)
    {
        // Maps a public clear token to the internal version-bump group name.
        $versionGroups = [
            'cache_category' => 'category',
            'cache_page' => 'page',
        ];

        try {
            // WHY: run synchronously (no defer). Every operation here is now a tiny
            // key write — the old defer() existed to keep the expensive Cache::flush()
            // off the response path, but that flush is gone. Running inline makes the
            // bump take effect immediately (also within the same request) and keeps the
            // behaviour deterministic/testable.
            if ($typeCache == 'cache_all') {
                // Scoped clear of every known GP247 group — NOT a global flush.
                foreach ($versionGroups as $group) {
                    gp247_cache_bump($group);
                }
                Cache::forget('cache_country');
            } elseif (isset($versionGroups[$typeCache])) {
                gp247_cache_bump($versionGroups[$typeCache]);
            } else {
                // Flat-keyed groups (e.g. cache_country) and any legacy direct key.
                Cache::forget($typeCache);
            }
            $response = ['error' => 0, 'msg' => 'Clear success!', 'action' => $typeCache];
        } catch (\Throwable $e) {
            $response = ['error' => 1, 'msg' => $e->getMessage(), 'action' => $typeCache];
        }
        return $response;
    }
}

if (!function_exists('gp247_cache_set') && !in_array('gp247_cache_set', config('gp247_functions_except', []))) {
    /**
     * Store a value in the cache using the admin-configured default TTL.
     *
     * @param string   $cacheIndex Cache key.
     * @param mixed    $value      Value to store.
     * @param int|null $time       TTL in seconds; falls back to the `cache_time`
     *                             config (validated >= 1 at the config screen) or 600.
     * @return void
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-AUI-cache-config-hardening
     */
    function gp247_cache_set($cacheIndex, $value, $time = null)
    {
        if (empty($cacheIndex)) {
            return ;
        }
        $seconds = $time ?? (gp247_config_global('cache_time') ?? 600);

        Cache::put($cacheIndex, $value, $seconds);
    }
}
