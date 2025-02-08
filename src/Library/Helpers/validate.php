<?php

if (!function_exists('gp247_check_view') && !in_array('gp247_check_view', config('gp247_functions_except', []))) {
    /**
     * Check view exist
     *
     * @param   [string]  $view path view
     *
     * @return  [string]         [$domain]
     */
    function gp247_check_view($view)
    {
        if (!view()->exists($view)) {
            gp247_report('View not found '.$view);
            echo  gp247_language_render('front.view_not_exist', ['view' => $view]);
            exit();
        }
    }
}


if (!function_exists('gp247_clean') && !in_array('gp247_clean', config('gp247_functions_except', []))) {
    /**
     * Clear data
     */
    function gp247_clean($data = null, $exclude = [], $hight = false)
    {
        if (is_array($data)) {
            array_walk($data, function (&$v, $k) use ($exclude, $hight) {
                if (is_array($v)) {
                    $v = gp247_clean($v, $exclude, $hight);
                } 
                if (is_string($v)) {
                    if (in_array($k, $exclude)) {
                        $v = $v;
                    } else {
                        if ($hight) {
                            $v = strip_tags($v);
                        }
                        $v = htmlspecialchars_decode($v);
                        $v = htmlspecialchars($v, ENT_COMPAT, 'UTF-8');
                    }
                }
            });
        }
        if (is_string($data)) {
            if ($hight) {
                $data = strip_tags($data);
            }
            $data = htmlspecialchars_decode($data);
            $data = htmlspecialchars($data, ENT_COMPAT, 'UTF-8');
        }
        return $data;
    }
}