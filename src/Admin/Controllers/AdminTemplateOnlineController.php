<?php
namespace GP247\Core\Admin\Controllers;

use GP247\Core\Admin\Controllers\RootAdminController;
use GP247\Core\Admin\Controllers\ExtensionOnlineController;
class AdminTemplateOnlineController extends RootAdminController
{
    use ExtensionOnlineController;
    public $type = 'Template';
    public $groupType = 'Templates';
    public $urlOnline = '';

    public function __construct()
    {
        parent::__construct();
        $this->urlOnline = gp247_route_admin('admin_template_online');
    }
}
