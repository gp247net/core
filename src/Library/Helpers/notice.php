<?php
if (!function_exists('gp247_notice_add')) {
    /**
     * [gp247_notice_add description]
     *
     * @param   string  $type     [$type description]
     * @param   string  $typeId   [$typeId description]
     * @param   string  $content  [$content description]
     * @param   [type]  $adminId  [$adminId description]
     * @param   [type]  $creator  [$creator description]
     *
     * @return  [type]            [return description]
     */
    function gp247_notice_add(string $type, string $typeId = '', string $content = '', $adminId = null, $creator = null)
    {
        $modelNotice = new GP247\Core\Models\AdminNotice;
        if ($adminId) {
            $listAdmin = is_array($adminId)? $adminId: [$adminId];
        } else {
            $listAdmin = gp247_notice_get_admin($type);
        }
        if ($creator) {
            $admin_created = $creator;
        } else {
            $admin_created = admin()->user()->id ?? 0;
        }
        if (count($listAdmin)) {
            foreach ($listAdmin as $key => $admin) {
                $modelNotice->create(
                    [
                        'type' => $type,
                        'type_id' => $typeId,
                        'admin_id' => $admin,
                        'admin_created' => $admin_created,
                        'content' => $content
                    ]
                );
            }
        }

    }

    /**
     * Get list id admin can get notice
     */
    if (!function_exists('gp247_notice_get_admin')) {
        function gp247_notice_get_admin(string $type = "")
        {
            if (function_exists('gp247_notice_custom_get_admin')) {
                return gp247_notice_custom_get_admin($type);
            }

            return (new \GP247\Core\Models\AdminUser)
            ->selectRaw('distinct '. GP247_DB_PREFIX.'admin_user.id')
            ->join(GP247_DB_PREFIX . 'admin_role_user', GP247_DB_PREFIX . 'admin_role_user.user_id', GP247_DB_PREFIX . 'admin_user.id')
            ->join(GP247_DB_PREFIX . 'admin_role', GP247_DB_PREFIX . 'admin_role.id', GP247_DB_PREFIX . 'admin_role_user.role_id')
            ->whereIn(GP247_DB_PREFIX . 'admin_role.slug', ['administrator','view.all'])
            ->pluck('id')
            ->toArray();
        }
    }

}
