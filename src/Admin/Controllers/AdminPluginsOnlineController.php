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
    public $listUrlAction = [];

    public function __construct()
    {
        parent::__construct();
        $this->urlOnline = gp247_route_admin('admin_plugin_online.index');
        $this->listUrlAction = $this->listUrlAction();
    }

    protected function listUrlAction()
    {
        return [
            'install' => gp247_route_admin('admin_plugin.install'),
            'uninstall' => gp247_route_admin('admin_plugin.uninstall'),
            'enable' => gp247_route_admin('admin_plugin.enable'),
            'disable' => gp247_route_admin('admin_plugin.disable'),
            'urlOnline' => gp247_route_admin('admin_plugin_online.index'),
            'urlImport' => gp247_route_admin('admin_plugin.import'),
        ];
    }
    protected function processUninstall(string $key)
    {
        //
    }

    protected function processDisable(string $key)
    {
        //
    }
}
