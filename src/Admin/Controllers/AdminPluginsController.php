<?php
namespace GP247\Core\Admin\Controllers;

use GP247\Core\Admin\Controllers\RootAdminController;
use GP247\Core\Admin\Controllers\ExtensionController;

class AdminPluginsController extends RootAdminController
{
    use ExtensionController;

    public $type = 'Plugin';
    public $groupType = 'Plugins';

    public function __construct()
    {
        parent::__construct();
    }
    
}
