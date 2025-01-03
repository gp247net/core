<?php
use GP247\Core\Admin\Models\AdminConfig;
use GP247\Core\Admin\Models\AdminHome;
use Illuminate\Support\Facades\Artisan;

if (!function_exists('gp247_extension_get_all_local') && !in_array('gp247_extension_get_all_local', config('gp247_functions_except', []))) {
    /**
     * Get all extension local
     *
     * @param   [string]  $code  Payment, Shipping
     *
     * @return  [array]
     */
    function gp247_extension_get_all_local($type = 'Plugin')
    {
        if ($type == 'Template') {
            $typeTmp = 'Templates';
        } else {
            $typeTmp = 'Plugins';
        }
        $arrClass = [];
        $dirs = array_filter(glob(app_path() . '/GP247/'.$typeTmp.'/*'), 'is_dir');
        if ($dirs) {
            foreach ($dirs as $dir) {
                $tmp = explode('/', $dir);
                $nameSpace = '\App\GP247\\' . $typeTmp.'\\'.end($tmp);
                if (file_exists($dir . '/AppConfig.php')) {
                    $arrClass[end($tmp)] = $nameSpace;
                }
            }
        }
        return $arrClass;
    }
}

if (!function_exists('gp247_extension_get_installed') && !in_array('gp247_extension_get_installed', config('gp247_functions_except', []))) {
    /**
     * Get all class plugin
     *
     *
     */
    function gp247_extension_get_installed($type = "Plugin", $active = true)
    {
        switch ($type) {
            case 'Template':
                return \GP247\Core\Admin\Models\AdminConfig::getTemplateCode($active);
                break;
            default:
            return \GP247\Core\Admin\Models\AdminConfig::getPluginCode($active);
                break;
        }
    }
}


    /**
     * Get namespace extension config
     *
     *
     * @return  [array]
     */
    if (!function_exists('gp247_extension_get_class_config') && !in_array('gp247_extension_get_class_config', config('gp247_functions_except', []))) {
        function gp247_extension_get_class_config(string $type="Plugin", string $key = "")
        {
            $key = gp247_word_format_class($key);

            $nameSpace = gp247_extension_get_namespace(type: $type, key:$key);
            $nameSpace = $nameSpace . '\AppConfig';

            return $nameSpace;
        }
    }

    /**
     * Get namespace module
     *
     * @param   [string]  $code  Block, Cms, Payment, shipping..
     * @param   [string]  $key  Content,Paypal, Cash..
     *
     * @return  [array]
     */
    if (!function_exists('gp247_extension_get_namespace') && !in_array('gp247_extension_get_namespace', config('gp247_functions_except', []))) {
        function gp247_extension_get_namespace(string $type="Plugin", string $key = "")
        {
            $key = gp247_word_format_class($key);
            switch ($type) {
                case 'Template':
                    $nameSpace = '\App\GP247\Templates\\' . $key;
                    break;
                default:
                    $nameSpace = '\App\GP247\Plugins\\' . $key;
                    break;
            }
            return $nameSpace;
        }
    }

    /**
     * Check plugin and template compatibility with GP247 version
     *
     * @param   string  $versionsConfig  [$versionsConfig description]
     *
     * @return  [type]                   [return description]
     */
    if (!function_exists('gp247_extension_check_compatibility') && !in_array('gp247_extension_check_compatibility', config('gp247_functions_except', []))) {
        function gp247_extension_check_compatibility(string $versionsConfig) {
            $arrVersionGP247 = explode('|', $versionsConfig);
            return in_array(config('gp247.core'), $arrVersionGP247);
        }
    }

    
if (!function_exists('gp247_extension_check_active') && !in_array('gp247_extension_check_active', config('gp247_functions_except', []))) {

    // Check extension is active
    function gp247_extension_check_active($group, $key)
    {
        $checkConfig = AdminConfig::where('store_id', GP247_ID_GLOBAL)
        ->where('key', $key)
        ->where('group', $group)
        ->where('value', 1)
        ->first();

        if ($checkConfig) {
            return true;
        } else {
            return false;
        }
    }
}

if (!function_exists('gp247_extension_check_installed') && !in_array('gp247_extension_check_installed', config('gp247_functions_except', []))) {

    // Check extension is installed
    function gp247_extension_check_installed($group, $key)
    {
        $checkConfig = AdminConfig::where('store_id', GP247_ID_GLOBAL)
        ->where('key', $key)
        ->where('group', $group)
        ->first();

        if ($checkConfig) {
            return true;
        } else {
            return false;
        }
    }
}


if (!function_exists('gp247_extension_update') && !in_array('gp247_extension_update', config('gp247_functions_except', []))) {

    // Process when extension update
    function gp247_extension_update()
    {
        try {
            // Check if file cache exist then clear cache and create new cache
            if(file_exists(base_path('bootstrap/cache/routes-v7.php'))) {
                Artisan::call('route:clear');
                Artisan::call('route:cache');
            }
    
            // Check if file cache exist then clear cache and create new cache
            if(file_exists(base_path('bootstrap/cache/config.php'))) {
                Artisan::call('config:clear');
                Artisan::call('config:cache');
            }
        } catch (\Throwable $e) {
            gp247_report($e->getMessage());
        }


    }
}