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
    function gp247_url_render($string = ""):string
    {
        $arrCheckRoute = explode('route::', $string);
        $arrCheckUrl = explode('admin::', $string);

        if (count($arrCheckRoute) == 2) {
            $arrRoute = explode('::', $string);
            if (Str::startsWith($string, 'route::admin')) {
                if (isset($arrRoute[2])) {
                    return gp247_route_admin($arrRoute[1], explode(',', $arrRoute[2]));
                } else {
                    return gp247_route_admin($arrRoute[1]);
                }
            } else {
                if (function_exists('gp247_route_front')) {
                    if (isset($arrRoute[2])) {
                        return gp247_route_front($arrRoute[1], explode(',', $arrRoute[2]));
                    } else {
                        return gp247_route_front($arrRoute[1]);
                    }
                } else {
                    if (isset($arrRoute[2])) {
                        return route($arrRoute[1], explode(',', $arrRoute[2]));
                    } else {
                        return route($arrRoute[1]);
                    }
                }

            }
        }

        if (count($arrCheckUrl) == 2) {
            $string = Str::start($arrCheckUrl[1], '/');
            $string = GP247_ADMIN_PREFIX . $string;
            return url($string);
        }
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
    function gp247_word_format_class($word = "")
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


if (!function_exists('gp247_push_include_view') && !in_array('gp247_push_include_view', config('gp247_functions_except', []))) {
    /**
     * Push view
     *
     * @param   [string]  $position
     * @param   [string]  $pathView
     *
     */
    function gp247_push_include_view($position = "", string $pathView = "")
    {
        $includePathView = config('gp247_include_view.'.$position, []);
        $includePathView[] = $pathView;
        config(['gp247_include_view.'.$position => $includePathView]);
    }
}


if (!function_exists('gp247_push_include_script') && !in_array('gp247_push_include_script', config('gp247_functions_except', []))) {
    /**
     * Push script
     *
     * @param   [string]  $position
     * @param   [string]  $pathScript
     *
     */
    function gp247_push_include_script($position, $pathScript)
    {
        $includePathScript = config('gp247_include_script.'.$position, []);
        $includePathScript[] = $pathScript;
        config(['gp247_include_script.'.$position => $includePathScript]);
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

if (!function_exists('gp247_request') && !in_array('gp247_request', config('gp247_functions_except', []))) {
    /**
     * Return value request
     */
    function gp247_request($key = "", $default = "", string $type = "")
    {

        if ($type == 'string') {
            if (is_array(request($key, $default))) {
                return 'array';
            }
        }

        if ($type == 'array') {
            if (is_string(request($key, $default))) {
                return [request($key, $default)];
            }
        }

        return request($key, $default);
    }
}


// Function get all package installed in composer.json
if (!function_exists('gp247_composer_get_package_installed') && !in_array('gp247_composer_get_package_installed', config('gp247_functions_except', []))) {
    function gp247_composer_get_package_installed()
    {
        $installed = \Composer\InstalledVersions::getAllRawData();
        return $installed;
    }
}

if (!function_exists('gp247_get_tables') && !in_array('gp247_get_tables', config('gp247_functions_except', []))) {
    /**
     * Get list of tables with prefix GP247_DB_PREFIX
     * @return array
     */
    function gp247_get_tables(): array
    {
        if (config('gp247-config.admin.schema_customize')) {
            return config('gp247-config.admin.schema_customize');
        }
        try {
            $connection = GP247_DB_CONNECTION;
            $prefix = GP247_DB_PREFIX;
            
            switch(config("database.connections.$connection.driver")) {
                case 'mysql':
                    $query = "SHOW TABLES LIKE '$prefix%'";
                    break;
                case 'sqlite':
                    $query = "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '$prefix%'";
                    break;
                case 'pgsql':
                    $schema = config("database.connections.$connection.schema", 'public');
                    $query = "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='$schema' AND tablename LIKE '$prefix%'";
                    break;
                case 'sqlsrv':
                    $query = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE '$prefix%'";
                    break;
                default:
                    return [];
            }

            $tables = DB::connection($connection)->select($query);
            return array_map(function($table) {
                $array = (array)$table;
                return array_shift($array);
            }, $tables);
            
        } catch (\Throwable $e) {
            gp247_handle_exception($e);
            return [];
        }
    }
}