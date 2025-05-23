<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Controllers\ExtensionController;

class AdminPluginsController extends RootAdminController
{
    use ExtensionController;

    public $groupType = 'Plugins';
    public $listUrlAction = [];

    public function __construct()
    {
        parent::__construct();
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
