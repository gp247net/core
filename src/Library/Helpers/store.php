<?php
use Illuminate\Support\Str;
use GP247\Core\Models\AdminStore;

/**
 * Get list store
 */
if (!function_exists('gp247_store_get_list_code') && !in_array('gp247_store_get_list_code', config('gp247_functions_except', []))) {
    function gp247_store_get_list_code()
    {
        return \GP247\Core\Models\AdminStore::getListStoreCode();
    }
}


/**
 * Get domain from code
 */
if (!function_exists('gp247_store_get_domain_from_code') && !in_array('gp247_store_get_domain_from_code', config('gp247_functions_except', []))) {
    function gp247_store_get_domain_from_code(string $code = ""):string
    {
        $domainList = \GP247\Core\Models\AdminStore::getStoreDomainByCode();
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
        $store = \GP247\Core\Models\AdminStore::find(GP247_STORE_ID_ROOT);
        return $store->domain;
    }
}

/**
 * Check store is partner
 */
if (!function_exists('gp247_store_is_partner') && !in_array('gp247_store_is_partner', config('gp247_functions_except', []))) {
    function gp247_store_is_partner(string $storeId):bool
    {
        $store = \GP247\Core\Models\AdminStore::find($storeId);
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
     * @param   $domain
     *
     * @return  [string]         [$domain]
     */
    function gp247_store_process_domain($domain)
    {
        // Return empty string if domain is null or not a string
        if ($domain === null || !is_string($domain)) {
            return "";
        }

        // Process domain string
        return rtrim(
            str_replace(
                ['http://', 'https://'], 
                '', 
                trim(strtolower($domain))
            ),
            '/'
        );
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
        gp247_store_check_multi_partner_installed() ||  gp247_store_check_multi_store_installed();
    }
}

if (!function_exists('gp247_store_check_multi_partner_installed') && !in_array('gp247_store_check_multi_partner_installed', config('gp247_functions_except', []))) {
    /**
     * Check partner installed
     * Partner have domain and different method to login, register, forgot password, etc.
     * It is necessary to check if the domain is active and whether it belongs to a valid partner with the right to use it.
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
     * Multistore only have different domain
     * It is necessary to check if the domain is active 
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
     * @param   [null|int]  $storeId    store id
     *
     * @return  [mix]
     */
    function gp247_store_info(string $key = null, $default = null, $storeId = null)
    {
        $storeId = ($storeId == null) ? config('app.storeId') : $storeId;

        if ($default == null && $key == 'template') {
            if (defined('GP247_TEMPLATE_FRONT_DEFAULT')) {
                $default = GP247_TEMPLATE_FRONT_DEFAULT;
            }
        }

        $allStoreInfo = [];
        try {
            $allStoreInfo = AdminStore::getListAll()[$storeId]->toArray() ?? [];
        } catch (\Throwable $e) {
            gp247_report($e->getMessage());
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


/**
 * Get store list of links
 */
if (!function_exists('gp247_store_get_list_domain_of_array_link') && !in_array('gp247_store_get_list_domain_of_array_link', config('gp247_functions_except', []))) {
    function gp247_store_get_list_domain_of_array_link($arrLinkId)
    {
        $tableStore = (new \GP247\Core\Models\AdminStore)->getTable();
        $tableLinkStore = (new \GP247\Front\Models\FrontLinkStore)->getTable();
        return \GP247\Front\Models\FrontLinkStore::select($tableStore.'.code', $tableStore.'.id', 'link_id')
            ->leftJoin($tableStore, $tableStore.'.id', $tableLinkStore.'.store_id')
            ->whereIn('link_id', $arrLinkId)
            ->get()
            ->groupBy('link_id');
    }
}

/**
 * Get list store of link detail
 */
if (!function_exists('gp247_store_get_list_domain_of_link_detail') && !in_array('gp247_store_get_list_domain_of_link_detail', config('gp247_functions_except', []))) {
    function gp247_store_get_list_domain_of_link_detail($cId)
    {
        return \GP247\Front\Models\FrontLinkStore::where('link_id', $cId)
            ->pluck('store_id')
            ->toArray();
    }
}

