<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
/*
String to Url
 */
if (!function_exists('gp247_word_format_url') && !in_array('gp247_word_format_url', config('gp247_functions_except', []))) {
    function gp247_word_format_url($str = ""):string
    {
        $unicode = array(
            'a' => 'á|à|ả|ã|ạ|ă|ắ|ặ|ằ|ẳ|ẵ|â|ấ|ầ|ẩ|ẫ|ậ',
            'd' => 'đ',
            'e' => 'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
            'i' => 'í|ì|ỉ|ĩ|ị',
            'o' => 'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ',
            'u' => 'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự',
            'y' => 'ý|ỳ|ỷ|ỹ|ỵ',
            'A' => 'Á|À|Ả|Ã|Ạ|Ă|Ắ|Ặ|Ằ|Ẳ|Ẵ|Â|Ấ|Ầ|Ẩ|Ẫ|Ậ',
            'D' => 'Đ',
            'E' => 'É|È|Ẻ|Ẽ|Ẹ|Ê|Ế|Ề|Ể|Ễ|Ệ',
            'I' => 'Í|Ì|Ỉ|Ĩ|Ị',
            'O' => 'Ó|Ò|Ỏ|Õ|Ọ|Ô|Ố|Ồ|Ổ|Ỗ|Ộ|Ơ|Ớ|Ờ|Ở|Ỡ|Ợ',
            'U' => 'Ú|Ù|Ủ|Ũ|Ụ|Ư|Ứ|Ừ|Ử|Ữ|Ự',
            'Y' => 'Ý|Ỳ|Ỷ|Ỹ|Ỵ',
        );

        foreach ($unicode as $nonUnicode => $uni) {
            $str = preg_replace("/($uni)/i", $nonUnicode, $str);
        }
        return strtolower(preg_replace(
            array('/[\s\-\/\\\?\(\)\~\.\[\]\%\*\#\@\$\^\&\!\'\"\`\;\:]+/'),
            array('-'),
            strtolower($str)
        ));
    }
}


if (!function_exists('gp247_url_render') && !in_array('gp247_url_render', config('gp247_functions_except', []))) {
    /*
    url render
     */
    function gp247_url_render($string = ""): string
    {
        if (empty($string)) {
            return url('/');
        }
        if ($string == "#") {
            return "#";
        }

        // Handle front:: or admin::
        if (Str::startsWith($string, ['route_front::','route_admin::'])) {
            $parts = explode('::', $string, 2);
            $prefix = $parts[0]; // 'route_front' or 'route_admin'
            $remaining = $parts[1];
            
            // Split route name and parameters
            $segments = explode(':', $remaining);
            $routeName = array_shift($segments);
            
            // Handle parameters if any
            $params = [];
            foreach ($segments as $segment) {
                $paramParts = explode('__', $segment, 2);
                if (count($paramParts) === 2) {
                    $params[$paramParts[0]] = $paramParts[1];
                }
            }
            
            if ($prefix == 'route_front') {
                if (function_exists('gp247_route_front')) {
                    return gp247_route_front($routeName, $params);
                } else {
                    return url($routeName, $params);
                }
            } else {
                if (function_exists('gp247_route_admin')) {
                    return gp247_route_admin($routeName, $params);
                } else {
                    return url($routeName, $params);
                }
            }
        }
        
        if (Str::startsWith($string, 'admin::')) {
            // Remove prefix admin::
            $string = Str::after($string, 'admin::');
            $string = Str::start($string, '/');
            $string = GP247_ADMIN_PREFIX . $string;
            return url($string);
        }

        // Other
        return url($string);
    }
}





if (!function_exists('gp247_html_render') && !in_array('gp247_html_render', config('gp247_functions_except', []))) {
    /*
    Html render
     */
    function gp247_html_render($string)
    {
        if(!is_string($string)) {
            return $string;
        }
        $string = htmlspecialchars_decode($string);
        return $string;
    }
}

if (!function_exists('gp247_word_format_class') && !in_array('gp247_word_format_class', config('gp247_functions_except', []))) {
    /*
    Format class name
     */
    function gp247_word_format_class($word = null)
    {
        if(!is_string($word)) {
            return $word;
        }
        $word = Str::camel($word);
        $word = ucfirst($word);
        return $word;
    }
}

if (!function_exists('gp247_word_limit') && !in_array('gp247_word_limit', config('gp247_functions_except', []))) {
    /*
    Truncates words
     */
    function gp247_word_limit($word = "", int $limit = 20, string $arg = ''):string
    {
        $word = Str::limit($word, $limit, $arg);
        return $word;
    }
}

if (!function_exists('gp247_token') && !in_array('gp247_token', config('gp247_functions_except', []))) {
    /*
    Create random token
     */
    function gp247_token(int $length = 32)
    {
        $token = Str::random($length);
        return $token;
    }
}

if (!function_exists('gp247_report') && !in_array('gp247_report', config('gp247_functions_except', []))) {
    /*
    Handle report
     */
    function gp247_report($msg = "", $channel = 'slack')
    {
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg);
        } elseif(is_string($msg)) {
            $msg = $msg;
        } else {
            $msg = 'Type of msg is not supported';
        }
        
        $msg = gp247_time_now(config('app.timezone')).' ('.config('app.timezone').'):'.PHP_EOL.$msg.PHP_EOL;

        if (is_string($channel)) {
            $channel = [$channel];
        }
        if (!is_array($channel)) {
            $channel = [];
        }
        
        if (count($channel)) {
            foreach ($channel as $key => $item) {
                if (in_array($item, array_keys(config('logging.channels')))) {
                    if ($item ==='slack') {
                        if (config('logging.channels.slack.url')) {
                            try {
                                \Log::channel('slack')->emergency($msg);
                            } catch (\Throwable $e) {
                                $msg .= $e->getFile().'- Line: '.$e->getLine().PHP_EOL.$e->getMessage().PHP_EOL;
                            }
                        }
                    } else {
                        try {
                            \Log::channel($item)->emergency($msg);
                        } catch (\Throwable $e) {
                            $msg .= $e->getFile().'- Line: '.$e->getLine().PHP_EOL.$e->getMessage().PHP_EOL;
                        }
                    }
                }
            }
        }
        \Log::channel('daily')->emergency($msg);
    }
}


if (!function_exists('gp247_handle_exception') && !in_array('gp247_handle_exception', config('gp247_functions_except', []))) {
    /*
    Process msg exception
     */
    function gp247_handle_exception(\Throwable $exception, $channel = 'slack')
    {
        $msg = "```". $exception->getMessage().'```'.PHP_EOL;
        $msg .= "```IP:```".request()->ip().PHP_EOL;
        $msg .= "*File* `".$exception->getFile()."`, *Line:* ".$exception->getLine().", *Code:* ".$exception->getCode().PHP_EOL.'URL= '.url()->current();
        if (function_exists('gp247_report') && $msg) {
            gp247_report(msg:$msg, channel:$channel);
        }
    }
}

/**
 * convert datetime to date
 */
if (!function_exists('gp247_datetime_to_date') && !in_array('gp247_datetime_to_date', config('gp247_functions_except', []))) {
    function gp247_datetime_to_date($datetime, $format = 'Y-m-d')
    {
        if (empty($datetime)) {
            return null;
        }
        return  date($format, strtotime($datetime));
    }
}


if (!function_exists('admin') && !in_array('admin', config('gp247_functions_except', []))) {
    /**
     * Admin login information
     */
    function admin()
    {
        return auth()->guard('admin');
    }
}

if (!function_exists('gp247_time_now') && !in_array('gp247_time_now', config('gp247_functions_except', []))) {
    /**
     * Return object carbon
     */
    function gp247_time_now($timezone = null)

    {
        return (new \Carbon\Carbon)->now($timezone);
    }
}


// Function get all package installed in composer.json
if (!function_exists('gp247_composer_get_package_installed') && !in_array('gp247_composer_get_package_installed', config('gp247_functions_except', []))) {
    function gp247_composer_get_package_installed()
    {
        // $installed = \Composer\InstalledVersions::getAllRawData();
        // return $installed;
        $installedPackages = \Composer\InstalledVersions::getInstalledPackages();
        foreach ($installedPackages as $package) {
            $packages[$package] = \Composer\InstalledVersions::getVersion($package);
        }
        ksort($packages);
        return $packages;
    }
}


// Check core actived
if (!function_exists('gp247_check_core_actived') && !in_array('gp247_check_core_actived', config('gp247_functions_except', []))) {
    function gp247_check_core_actived()   
    {
        if ((GP247_ACTIVE == 1 && \Illuminate\Support\Facades\Storage::disk('local')->exists('gp247-installed.txt'))) {
            return true;
        }
        return false;
    }
}
