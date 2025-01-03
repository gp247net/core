<?php
namespace GP247\Core\Admin\Controllers;

use GP247\Core\Admin\Controllers\RootAdminController;
use GP247\Core\Admin\Controllers\ExtensionController;
use GP247\Core\Admin\Models\AdminStore;

class AdminTemplateController extends RootAdminController
{
    use ExtensionController;

    public $type = 'Template';
    public $groupType = 'Templates';

    public function __construct()
    {
        parent::__construct();
        
    }
}
