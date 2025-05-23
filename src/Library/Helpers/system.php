<?php
use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminStore;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Arr;

if (!function_exists('gp247_admin_can_config')) {
    /**
     * Check user can change config value
     *
     * @return  [type]          [return description]
     */
    function gp247_admin_can_config()
    {
        return admin()->user()->checkPermissionConfig();
    }
}

if (!function_exists('gp247_config') && !in_array('gp247_config', config('gp247_functions_except', []))) {
    /**
     * Get value config from table gp247_config
     * Default value is only used if the config key does not exist (including null values)
     *
     * @param   [string|array]  $key      [$key description]
     * @param   [int|null]  $storeId  [$storeId description]
     * @param   [string|null]  $default  [$default description]
     *
     * @return  [type]            [return description]
     */
    function gp247_config($key = "", $storeId = null, $default = null)
    {
        $storeId = ($storeId === null) ? config('app.storeId') : $storeId;
        if (!is_string($key)) {
            return;
        }

        $allConfig = AdminConfig::getAllConfigOfStore($storeId);

        if ($key === "") {
            return $allConfig;
        }
        return array_key_exists($key, $allConfig) ? $allConfig[$key] : 
            (array_key_exists($key, gp247_config_global()) ? gp247_config_global()[$key] : $default);
    }
}


if (!function_exists('gp247_config_admin') && !in_array('gp247_config_admin', config('gp247_functions_except', []))) {
    /**
     * Get config value in adin with session store id
     * Default value is only used if the config key does not exist (including null values)
     *
     * @param   [type]$key  [$key description]
     * @param   null        [ description]
     *
     * @return  [type]      [return description]
     */
    function gp247_config_admin($key = "", $default = null)
    {
        return gp247_config($key, session('adminStoreId'), $default);
    }
}


if (!function_exists('gp247_config_global') && !in_array('gp247_config_global', config('gp247_functions_except', []))) {
    /**
     * Get value config from table gp247_config for store_id 0
     * Default value is only used if the config key does not exist (including null values)
     *
     * @param   [string|array] $key      [$key description]
     * @param   [string|null]  $default  [$default description]
     *
     * @return  [type]          [return description]
     */
    function gp247_config_global($key = "", $default = null)
    {
        if (!is_string($key)) {
            return;
        }
        $allConfig = [];
        try {
            $allConfig = AdminConfig::getAllGlobal();
        } catch (\Throwable $e) {
            //
        }
        if ($key === "") {
            return $allConfig;
        }
        if (!array_key_exists($key, $allConfig)) {
            return $default;
        } else {
            return trim($allConfig[$key]);
        }
    }
}

if (!function_exists('gp247_config_group') && !in_array('gp247_config_group', config('gp247_functions_except', []))) {
    /*
    Group Config info
     */
    function gp247_config_group($group = null, $suffix = null)
    {
        $groupData = AdminConfig::getGroup($group, $suffix);
        return $groupData;
    }
}


if (!function_exists('gp247_route_admin') && !in_array('gp247_route_admin', config('gp247_functions_except', []))) {
    /**
     * Render route admin
     *
     * @param   [string]  $name
     * @param   [array]  $param
     *
     * @return  [type]         [return description]
     */
    function gp247_route_admin($name, $param = [])
    {
        $name = trim($name);
        if (Route::has($name)) {
            try {
                $route = route($name, $param);
            } catch (\Throwable $th) {
                $route = url('#'.$name.'#'.implode(',', $param));
            }
            return $route;
        } else {
            return url('#'.$name);
        }
    }
}

if (!function_exists('gp247_uuid') && !in_array('gp247_uuid', config('gp247_functions_except', []))) {
    /**
     * Generate UUID
     *
     * @param   [string]  $name
     * @param   [array]  $param
     *
     * @return  [type]         [return description]
     */
    function gp247_uuid()
    {
        return (string)\Illuminate\Support\Str::orderedUuid();
    }
}

if (!function_exists('gp247_generate_id') && !in_array('gp247_generate_id', config('gp247_functions_except', []))) {
    /**
     * Generate ID
     *     *
     * @return  [type]         [return description]
     */
    function gp247_generate_id($prefix = null)
    {
        if ($prefix) {
            return strtoupper($prefix).'-'.gp247_token(5);
        } else {
            return gp247_uuid();
        }
    }
}


if (!function_exists('gp247_config_update') && !in_array('gp247_config_update', config('gp247_functions_except', []))) {

    /**
     * Update key config
     *
     * @param   array  $dataUpdate  [$dataUpdate description]
     * @param   [type] $storeId     [$storeId description]
     *
     * @return  [type]              [return description]
     */
    function gp247_config_update($dataUpdate = null, $storeId = null)
    {
        $storeId = ($storeId === null) ? config('app.storeId') : $storeId;
        //Update config
        if (is_array($dataUpdate)) {
            if (count($dataUpdate) == 1) {
                foreach ($dataUpdate as $k => $v) {
                    return AdminConfig::where('store_id', $storeId)
                        ->where('key', $k)
                        ->update(['value' => $v]);
                }
            } else {
                return false;
            }
        }
        //End update
    }
}

if (!function_exists('gp247_config_exist') && !in_array('gp247_config_exist', config('gp247_functions_except', []))) {

    /**
     * Check key config exist
     *
     * @param   [type]  $key      [$key description]
     * @param   [type]  $storeId  [$storeId description]
     *
     * @return  [type]            [return description]
     */
    function gp247_config_exist($key = "", $storeId = null)
    {
        if(!is_string($key)) {
            return false;
        }
        $storeId = ($storeId === null) ? config('app.storeId') : $storeId;
        $checkConfig = AdminConfig::where('store_id', $storeId)->where('key', $key)->first();
        if ($checkConfig) {
            return true;
        } else {
            return false;
        }
    }
}

if (!function_exists('gp247_config_global_update') && !in_array('gp247_config_global_update', config('gp247_functions_except', []))) {
    /**
     * [gp247_config_global_update description]
     *
     * @param   [type]  $arrayData  [$arrayData description]
     *
     * @return  []                  [return description]
     */
    function gp247_config_global_update($arrayData = [])
    {
        //Update config
        if (is_array($arrayData)) {
            if (count($arrayData) == 1) {
                foreach ($arrayData as $k => $v) {
                    return AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
                        ->where('key', $k)
                        ->update(['value' => $v]);
                }
            } else {
                return false;
            }
        } else {
            return;
        }
        //End update
    }
}


if (!function_exists('gp247_add_module') && !in_array('gp247_add_module', config('gp247_functions_except', []))) {

    
    function gp247_add_module(string $position, string $view, $sort = 0) {
        $positions = config('gp247-module.'.$position) ?? [];
        $positions[] = [
            'view' => $view,
            'sort' => $sort
        ];
        //Sort by sort
        usort($positions, function($a, $b) {
            return $a['sort'] <=> $b['sort'];
        });
        config(['gp247-module.'.$position => $positions]);
    }
}