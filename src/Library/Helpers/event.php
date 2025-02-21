<?php
if (!function_exists('gp247_event_admin_login') && !in_array('gp247_event_admin_login', config('gp247_functions_except', []))) {
    /**
     * [gp247_event_admin_login description]
     *
     * @param   string  $str  [$str description]
     *
     * @return  [type]        [return description]
     */
    function gp247_event_admin_login(\GP247\Core\Models\AdminUser $user)
    {
        if (function_exists('partner_event_admin_login')) {
            partner_event_admin_login($user);
        }
    }
}
if (!function_exists('gp247_event_admin_created') && !in_array('gp247_event_admin_created', config('gp247_functions_except', []))) {
    /**
     * [gp247_event_admin_created description]
     *
     * @param   string  $str  [$str description]
     *
     * @return  [type]        [return description]
     */
    function gp247_event_admin_created(\GP247\Core\Models\AdminUser $user)
    {
        if (function_exists('partner_event_admin_add')) {
            partner_event_admin_add($user);
        }
        gp247_notice_add(type: 'Admin', typeId: $user->id, content:'admin_notice.gp247_new_admin_add::name__'.$user->name);
    }
}
if (!function_exists('gp247_event_admin_deleting') && !in_array('gp247_event_admin_deleting', config('gp247_functions_except', []))) {
    /**
     * [gp247_event_admin_deleting description]
     *
     * @param   string  $str  [$str description]
     *
     * @return  [type]        [return description]
     */
    function gp247_event_admin_deleting(\GP247\Core\Models\AdminUser $user)
    {
        if (function_exists('partner_event_admin_delete')) {
            partner_event_admin_delete($user);
        }
        gp247_notice_add(type: 'Admin', typeId: $user->id, content:'admin_notice.gp247_new_admin_delete::name__'.$user->name);
    }
}