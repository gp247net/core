<?php
namespace GP247\Core\Admin\Controllers;

use GP247\Core\Admin\Controllers\RootAdminController;
use GP247\Core\Admin\Controllers\ExtensionOnlineController;
class AdminPluginsOnlineController extends RootAdminController
{
    use ExtensionOnlineController;

    public $type = 'Plugin';
    public $groupType = 'Plugins';
    public $urlOnline = '';

    public function __construct()
    {
        parent::__construct();
        $this->urlOnline = gp247_route_admin('admin_plugin_online.index');
    }
}
