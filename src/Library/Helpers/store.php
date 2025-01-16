<?php
use Illuminate\Support\Str;
use GP247\Core\Admin\Models\AdminStore;

/**
 * Get list store
 */
if (!function_exists('gp247_store_get_list_code') && !in_array('gp247_store_get_list_code', config('gp247_functions_except', []))) {
    function gp247_store_get_list_code()
    {
        return \GP247\Core\Admin\Models\AdminStore::getListStoreCode();
    }
}


/**
 * Get domain from code
 */
if (!function_exists('gp247_store_get_domain_from_code') && !in_array('gp247_store_get_domain_from_code', config('gp247_functions_except', []))) {
    function gp247_store_get_domain_from_code(string $code = ""):string
    {
        $domainList = \GP247\Core\Admin\Models\AdminStore::getStoreDomainByCode();
        if (!empty($domainList[$code])) {
            return 'http://'.$domainList[$code];
        } else {
            return url('/');
        }
    }
}

/**
 * Get domain root
 */
if (!function_exists('gp247_store_get_domain_root') && !in_array('gp247_store_get_domain_root', config('gp247_functions_except', []))) {
    function gp247_store_get_domain_root():string
    {
        $store = \GP247\Core\Admin\Models\AdminStore::find(GP247_STORE_ID_ROOT);
        return $store->domain;
    }
}

/**
 * Check store is partner
 */
if (!function_exists('gp247_store_is_partner') && !in_array('gp247_store_is_partner', config('gp247_functions_except', []))) {
    function gp247_store_is_partner(string $storeId):bool
    {
        $store = \GP247\Core\Admin\Models\AdminStore::find($storeId);
        if (!$store) {
            return false;
        }
        return $store->partner || $storeId == GP247_STORE_ID_ROOT;
    }
}

/**
 * Check store is root
 */
if (!function_exists('gp247_store_is_root') && !in_array('gp247_store_is_root', config('gp247_functions_except', []))) {
    function gp247_store_is_root(string $storeId):bool
    {
        return  $storeId == GP247_STORE_ID_ROOT;
    }
}

if (!function_exists('gp247_store_process_domain') && !in_array('gp247_store_process_domain', config('gp247_functions_except', []))) {
    /**
     * Process domain store
     *
     * @param   [string]  $domain
     *
     * @return  [string]         [$domain]
     */
    function gp247_store_process_domain(string $domain = "")
    {
        $domain = str_replace(['http://', 'https://'], '', $domain);
        $domain = Str::lower($domain);
        $domain = rtrim($domain, '/');
        return $domain;
    }
}

if (!function_exists('gp247_store_check_multi_domain_installed') && !in_array('gp247_store_check_multi_domain_installed', config('gp247_functions_except', []))) {
/**
 * Check plugin multi domain installed
 *
 * @return
 */
    function gp247_store_check_multi_domain_installed()
    {
        return 
        gp247_config_global('MultiVendorPro') 
        || gp247_config_global('MultiVendor') 
        || gp247_config_global('MultiStorePro')
        || gp247_config_global('Pmo');
    }
}

if (!function_exists('gp247_store_check_multi_partner_installed') && !in_array('gp247_store_check_multi_partner_installed', config('gp247_functions_except', []))) {
    /**
     * Check plugin multi vendor installed
     * partner can login with different admin screen
     *
     * @return
     */
        function gp247_store_check_multi_partner_installed()
        {
            return 
            gp247_config_global('MultiVendorPro') 
            || gp247_config_global('MultiVendor')
            || gp247_config_global('Pmo');
        }
}

if (!function_exists('gp247_store_check_multi_store_installed') && !in_array('gp247_store_check_multi_store_installed', config('gp247_functions_except', []))) {
    /**
     * Check plugin multi store installed
     *
     * @return
     */
        function gp247_store_check_multi_store_installed()
        {
            return gp247_config_global('MultiStorePro');
        }
}

if (!function_exists('gp247_store_get_list_active') && !in_array('gp247_store_get_list_active', config('gp247_functions_except', []))) {
    function gp247_store_get_list_active($field = null)
    {
        switch ($field) {
            case 'code':
                return AdminStore::getCodeActive();
                break;

            case 'domain':
                return AdminStore::getStoreActive();
                break;

            default:
                return AdminStore::getListAllActive();
                break;
        }
    }
}


if (!function_exists('gp247_store_info') && !in_array('gp247_store_info', config('gp247_functions_except', []))) {
    /**
     * Get info store_id, table admin_store
     *
     * @param   [string] $key      [$key description]
     * @param   [null|int]  $store_id    store id
     *
     * @return  [mix]
     */
    function gp247_store_info($key = null, $default = null, $store_id = null)
    {
        $store_id = ($store_id == null) ? config('app.storeId') : $store_id;

        //Update store info
        if (is_array($key)) {
            if (count($key) == 1) {
                foreach ($key as $k => $v) {
                    return AdminStore::where('id', $store_id)->update([$k => $v]);
                }
            } else {
                return false;
            }
        }
        //End update

        $allStoreInfo = [];
        try {
            $allStoreInfo = AdminStore::getListAll()[$store_id]->toArray() ?? [];
        } catch (\Throwable $e) {
            //
        }

        $lang = app()->getLocale();
        $descriptions = $allStoreInfo['descriptions'] ?? [];
        foreach ($descriptions as $row) {
            if ($lang == $row['lang']) {
                $allStoreInfo += $row;
            }
        }
        if ($key == null) {
            return $allStoreInfo;
        }
        return $allStoreInfo[$key] ?? $default;
    }
}



if (!function_exists('gp247_store_process_domain') && !in_array('gp247_store_process_domain', config('gp247_functions_except', []))) {
    /**
     * Process domain store
     *
     * @param   [string]  $domain
     *
     * @return  [string]         [$domain]
     */
    function gp247_store_process_domain(string $domain = "")
    {
        $domain = str_replace(['http://', 'https://'], '', $domain);
        $domain = Str::lower($domain);
        $domain = rtrim($domain, '/');
        return $domain;
    }
}

