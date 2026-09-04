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
            return 'https://'.$domainList[$code];
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
            || gp247_config_global('Pmo247');
        }
}

if (!function_exists('gp247_store_check_multi_store_installed') && !in_array('gp247_store_check_multi_store_installed', config('gp247_functions_except', []))) {
    /**
     * Check plugin multi store installed
     * Multistore only have different domain
     * It is necessary to check if the domain is active
     *
     * WHY two keys: the Free edition was renamed `MultiStorePro` -> `MultiStore`
     * (P0.5). Reading both keeps sites that installed the old key working, and is
     * semantically correct — Free or Pro, either one enables multi-store. Mirrors
     * gp247_store_check_multi_partner_installed() above, which checks three keys.
     *
     * @return
     */
        function gp247_store_check_multi_store_installed()
        {
            return gp247_config_global('MultiStore') || gp247_config_global('MultiStorePro');
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
    function gp247_store_info(string $key, $default = null, $storeId = null)
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
 * Get store list of links grouped by link_id.
 * Requires gp247/front to be installed (FrontLink model).
 * Returns empty collection when front package is absent.
 *
 * @param array $arrLinkId
 * @return \Illuminate\Support\Collection
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-006
 * @aidlc-adr multi-store_one-to-one-store-ownership
 */
if (!function_exists('gp247_store_get_list_domain_of_array_link') && !in_array('gp247_store_get_list_domain_of_array_link', config('gp247_functions_except', []))) {
    function gp247_store_get_list_domain_of_array_link($arrLinkId)
    {
        // WHY: front package is optional when core runs standalone (MC-009 / US-CMP-006).
        if (!class_exists(\GP247\Front\Models\FrontLink::class)) {
            return collect();
        }
        // WHY: 1-1 ownership — resolve each link's owning store from its own store_id
        // column (join admin_store) and keep the groupBy('link_id') shape.
        $tableStore = (new \GP247\Core\Models\AdminStore)->getTable();
        $tableLink = (new \GP247\Front\Models\FrontLink)->getTable();
        return \GP247\Front\Models\FrontLink::select($tableStore.'.code', $tableStore.'.id', $tableLink.'.id as link_id')
            ->join($tableStore, $tableStore.'.id', $tableLink.'.store_id')
            ->whereIn($tableLink.'.id', $arrLinkId)
            ->get()
            ->groupBy('link_id');
    }
}

/**
 * Get list of store IDs associated with a link.
 * Requires gp247/front to be installed (FrontLink model).
 * Returns empty array when front package is absent.
 *
 * @param mixed $cId Link ID
 * @return array
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-006
 * @aidlc-adr multi-store_one-to-one-store-ownership
 */
if (!function_exists('gp247_store_get_list_domain_of_link_detail') && !in_array('gp247_store_get_list_domain_of_link_detail', config('gp247_functions_except', []))) {
    function gp247_store_get_list_domain_of_link_detail($cId)
    {
        // WHY: front package is optional when core runs standalone (MC-009 / US-CMP-006).
        if (!class_exists(\GP247\Front\Models\FrontLink::class)) {
            return [];
        }
        // WHY: 1-1 ownership — a link owns a single store_id column; return it as a
        // 1-element array to keep the historical array return shape.
        $storeId = \GP247\Front\Models\FrontLink::where('id', $cId)->value('store_id');
        return $storeId ? [$storeId] : [];
    }
}

/**
 * Resolve the admin's current store-context id.
 *
 * Default = ROOT, with no DB query and no work on the hot path unless a resolver
 * is registered. When the Pro edition registers a callable at
 * config('gp247-config.admin.store_resolver'), that callable decides the store
 * (and MUST do its own permission check — the value's origin is user input).
 * Fail-safe: an empty/non-callable registry, a null/falsy result, or any thrown
 * error all fall back to ROOT, so a broken resolver can never leak another store
 * nor blank the context. An empty registry ⇒ behaviour identical to the previous
 * hard-pinned ROOT (NFR-MAINT-admin-store-scope-parity).
 *
 * @aidlc-unit multi-store-pro
 * @aidlc-story US-multi-store-pro-admin-store-switcher
 * @aidlc-adr multi-store_admin-store-scope-seam
 * @return int|string
 */
if (!function_exists('gp247_admin_store_resolve') && !in_array('gp247_admin_store_resolve', config('gp247_functions_except', []))) {
    function gp247_admin_store_resolve()
    {
        $resolver = config('gp247-config.admin.store_resolver');
        if (empty($resolver) || !is_callable($resolver)) {
            return GP247_STORE_ID_ROOT;
        }
        try {
            $storeId = call_user_func($resolver);
        } catch (\Throwable $e) {
            gp247_report($e);
            return GP247_STORE_ID_ROOT;
        }
        return $storeId ?: GP247_STORE_ID_ROOT;
    }
}


/**
 * The store a PLUGIN's config/behaviour applies to for the current request — the
 * single "effective store" seam that lets one plugin serve both supported topologies
 * (ADR plugin-manager_per-store-plugin-config):
 *   - admin request  → session('adminStoreId')  (ROOT at root admin; the bound store
 *     for a store-admin/vendor — same value ResourcePanel/ConfigForm scope to);
 *   - marketplace checkout → session('storeCheckout') (cart is grouped per store, one
 *     order per vendor; app.storeId stays ROOT on the shared marketplace domain);
 *   - everything else → config('app.storeId') (the domain's store in multi-store;
 *     ROOT on a single-store site).
 *
 * Callers read config with gp247_config($key, gp247_plugin_store_id()) and filter
 * enable/disable with the same value, so no per-topology condition is scattered across
 * plugins. On a single-store site every branch resolves to ROOT ⇒ gp247_config's
 * per-store memo means no extra query (NFR-MAINT-store-scope-single-mechanism).
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-per-store-plugin-config
 * @aidlc-adr plugin-manager_per-store-plugin-config
 * @return int|string
 */
if (!function_exists('gp247_plugin_store_id') && !in_array('gp247_plugin_store_id', config('gp247_functions_except', []))) {
    function gp247_plugin_store_id()
    {
        // Admin context: the session store the admin shell already resolved + checked.
        if (function_exists('admin') && admin()->user()) {
            return session('adminStoreId', GP247_STORE_ID_ROOT);
        }
        // Marketplace checkout: the vendor store the cart segment belongs to.
        $storeCheckout = session('storeCheckout');
        if (!empty($storeCheckout)) {
            return $storeCheckout;
        }
        // Storefront: the store serving this domain (ROOT on a single-store site).
        return config('app.storeId', GP247_STORE_ID_ROOT);
    }
}

