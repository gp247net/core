<?php
#App\GP247\Plugins\Extension_Key\Models\ExtensionModel.php
namespace App\GP247\Plugins\Extension_Key\Models;

class ExtensionModel
{
    public function uninstallExtension()
    {
        return ['error' => 0, 'msg' => 'uninstall success'];
    }

    public function installExtension()
    {
        return ['error' => 0, 'msg' => 'install success'];
    }
    
}
