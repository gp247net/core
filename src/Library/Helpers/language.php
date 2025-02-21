<?php

use GP247\Core\Models\AdminLanguage;
use Illuminate\Support\Str;

if (!function_exists('gp247_language_all') && !in_array('gp247_language_all', config('gp247_functions_except', []))) {
    //Get all language
    function gp247_language_all()
    {
        return AdminLanguage::getListActive();
    }
}

if (!function_exists('gp247_languages') && !in_array('gp247_languages', config('gp247_functions_except', []))) {
    /*
    Render language
    WARNING: Dont call this function (or functions that call it) in __construct or midleware, it may cause the display language to be incorrect
     */
    function gp247_languages($locale)
    {
        $languages = \GP247\Core\Models\Languages::getListAll($locale);
        return $languages;
    }
}

if (!function_exists('gp247_language_replace') && !in_array('gp247_language_replace', config('gp247_functions_except', []))) {
    /*
    Replace language
     */
    function gp247_language_replace(string $line, array $replace)
    {
        foreach ($replace as $key => $value) {
            $line = str_replace(
                [':'.$key, ':'.Str::upper($key), ':'.Str::ucfirst($key)],
                [$value, Str::upper($value), Str::ucfirst($value)],
                $line
            );
        }
        return $line;
    }
}


if (!function_exists('gp247_language_render') && !in_array('gp247_language_render', config('gp247_functions_except', []))) {
    /*
    Render language
    WARNING: Dont call this function (or functions that call it) in __construct or midleware, it may cause the display language to be incorrect
     */
    function gp247_language_render($string, array $replace = [], $locale = null)
    {
        if (!is_string($string)) {
            return null;
        }
        $locale = $locale ? $locale : gp247_get_locale();
        $languages = gp247_languages($locale);
        return !empty($languages[$string]) ? gp247_language_replace($languages[$string], $replace): trans($string, $replace);
    }
}


if (!function_exists('gp247_language_quickly') && !in_array('gp247_language_quickly', config('gp247_functions_except', []))) {
    /*
    Language quickly
     */
    function gp247_language_quickly($string, $default = null)
    {
        $locale = gp247_get_locale();
        $languages = gp247_languages($locale);
        return !empty($languages[$string]) ? $languages[$string] : (\Lang::has($string) ? trans($string) : $default);
    }
}

if (!function_exists('gp247_get_locale') && !in_array('gp247_get_locale', config('gp247_functions_except', []))) {
    /*
    Get locale
    */
    function gp247_get_locale()
    {
        return app()->getLocale();
    }
}


if (!function_exists('gp247_lang_switch') && !in_array('gp247_lang_switch', config('gp247_functions_except', []))) {
    /**
     * Switch language
     *
     * @param   [string]  $lang
     *
     * @return  [mix]
     */
    function gp247_lang_switch($lang = null)
    {
        if (!$lang) {
            return ;
        }

        $languages = gp247_language_all()->keys()->all();
        if (in_array($lang, $languages)) {
            app()->setLocale($lang);
            session(['locale' => $lang]);
        } else {
            return abort(404);
        }
    }
}

if (!function_exists('gp247_content_render') && !in_array('gp247_content_render', config('gp247_functions_except', []))) {
    function gp247_content_render(string $content)
    {
        // $content = 'keyLang::param1__value1::param2__value2';
        
        $tmpString = explode('::', $content);
        count($tmpString);
        for ($i=0; $i < count($tmpString); $i++) {
            $tmpParam = explode('__', $tmpString[$i]);
            $arrParam[$tmpParam[0]]= $tmpParam[1] ?? '';
        }
        return gp247_language_render($tmpString[0], $arrParam);
    }
}