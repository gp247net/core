<?php

if (!function_exists('gp247_captcha_method') && !in_array('gp247_captcha_method', config('gp247_functions_except', []))) {
    function gp247_captcha_method()
    {
        //If function captcha disable or dont setup
        if (empty(gp247_config('captcha_mode'))) {
            return null;
        }

        // If method captcha selected
        if (!empty(gp247_config('captcha_method'))) {
            $moduleClass = gp247_config('captcha_method');
            //If class plugin captcha exist
            if (class_exists($moduleClass)) {
                //Check plugin captcha disable
                $key = (new $moduleClass)->configKey;
                if (gp247_config($key)) {
                    return (new $moduleClass);
                } else {
                    return null;
                }
            }
        }
        return null;
    }
}

if (!function_exists('gp247_captcha_page') && !in_array('gp247_captcha_page', config('gp247_functions_except', []))) {
    function gp247_captcha_page():array
    {
        if (empty(gp247_config('captcha_page'))) {
            return [];
        }

        if (!empty(gp247_config('captcha_page'))) {
            return json_decode(gp247_config('captcha_page'));
        }
    }
}

if (!function_exists('gp247_captcha_get_plugin_installed') && !in_array('gp247_captcha_get_plugin_installed', config('gp247_functions_except', []))) {
    /**
     * Get all class plugin captcha installed
     *
     * @param   [string]  $code  Payment, Shipping
     *
     */
    function gp247_captcha_get_plugin_installed($onlyActive = true)
    {
        $listPluginInstalled =  \GP247\Core\Models\AdminConfig::getPluginCaptchaCode($onlyActive);
        $arrPlugin = [];
        if ($listPluginInstalled) {
            foreach ($listPluginInstalled as $key => $plugin) {
                $keyPlugin = gp247_word_format_class($plugin->key);
                $appPath = app_path() . '/GP247/Plugins/'.$keyPlugin;
                $nameSpaceConfig = '\App\GP247\Plugins\\'.$keyPlugin.'\AppConfig';
                if (file_exists($appPath . '/AppConfig.php') && class_exists($nameSpaceConfig)) {
                    $arrPlugin[$nameSpaceConfig] = gp247_language_render($plugin->detail);
                }
            }
        }
        return $arrPlugin;
    }
}


if (!function_exists('gp247_captcha_processview') && !in_array('gp247_captcha_processview', config('gp247_functions_except', []))) {
    /**
     * Process view captcha
     *
     * @param   [string]  $position  Position captcha
     * @param   [string]  $name      Name button captcha
     *
     */
    function gp247_captcha_processview($position = '', $name = 'Submit')
    {
        $viewCaptcha = '';
        if (gp247_captcha_method() && in_array($position, gp247_captcha_page())) {
            if (view()->exists(gp247_captcha_method()->appPath.'::render')) {
                $dataView = [
                    'titleButton' => $name,
                    'idForm' => 'gp247-form-process',
                    'idButtonForm' => 'gp247-button-process',
                ];
                $viewCaptcha = view(gp247_captcha_method()->appPath.'::render', $dataView)->render();
            }
        }
        return $viewCaptcha;
    }
}