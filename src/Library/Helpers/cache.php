<?php

use \Illuminate\Support\Facades\Cache;

if (!function_exists('gp247_cache_clear') && !in_array('gp247_cache_clear', config('gp247_functions_except', []))) {
    /**
     * Clear cache
     *
     * @param   [string]  $domain
     *
     * @return  [string]         [$domain]
     */
    function gp247_cache_clear($typeCache = 'cache_all', $storeId = null)
    {

        $storeI = $storeId ?? session('adminStoreId');
        try {
            if ($typeCache == 'cache_all') {
                defer(fn () => Cache::flush());
            } else {
                defer(fn () => Cache::forget($typeCache));
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
     * [gp247_cache_set description]
     *
     * @param   [string]$cacheIndex  [$cacheIndex description]
     * @param   [type]$value       [$value description]
     * @param   [seconds]$time        [$time description]
     * @param   null               [ description]
     *
     * @return  [type]             [return description]
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
