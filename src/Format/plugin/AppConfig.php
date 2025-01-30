<?php
/**
 * Plugin format 1.0
 */
#App\GP247\Plugins\Extension_Key\AppConfig.php
namespace App\GP247\Plugins\Extension_Key;

use App\GP247\Plugins\Extension_Key\Models\ExtensionModel;
use GP247\Core\Admin\Models\AdminConfig;
use GP247\Core\Admin\Models\AdminHome;
use GP247\Core\ExtensionConfigDefault;
use Illuminate\Support\Facades\DB;
class AppConfig extends ExtensionConfigDefault
{
    public function __construct()
    {
        //Read config from gp247.json
        $config = file_get_contents(__DIR__.'/gp247.json');
        $config = json_decode($config, true);
    	$this->configGroup = $config['configGroup'];
        $this->configKey = $config['configKey'];
        $this->gp247Version = $config['gp247Version'];
        //Path
        $this->appPath = $this->configGroup . '/' . $this->configKey;
        //Language
        $this->title = trans($this->appPath.'::lang.title');
        //Image logo or thumb
        $this->image = $this->appPath.'/'.$config['image'];
        //
        $this->version = $config['version'];
        $this->auth = $config['auth'];
        $this->link = $config['link'];
    }

    public function install()
    {
        $return = ['error' => 0, 'msg' => ''];
        $check = AdminConfig::where('key', $this->configKey)
            ->where('group', $this->configGroup)->first();
        if ($check) {
            //Check Plugin key exist
            $return = ['error' => 1, 'msg' =>  gp247_language_render('admin.extension.plugin_exist')];
        } else {
            //Insert plugin to config
            $dataInsert = [
                [
                    'group'  => $this->configGroup,
                    'key'    => $this->configKey,
                    'code'    => $this->configKey,
                    'sort'   => 0,
                    'store_id' => GP247_STORE_ID_GLOBAL,
                    'value'  => self::ON, //Enable extension
                    'detail' => $this->appPath.'::lang.title',
                ],
            ];
            DB::connection(GP247_DB_CONNECTION)->beginTransaction();
            try {
                AdminConfig::insert(
                    $dataInsert
                );
                (new ExtensionModel)->installExtension();
                DB::connection(GP247_DB_CONNECTION)->commit();
                $return = ['error' => 0, 'msg' => 'Install success'];
            } catch (\Throwable $e) {
                DB::connection(GP247_DB_CONNECTION)->rollBack();
                $return = ['error' => 1, 'msg' => $e->getMessage()];
            }
        }

        return $return;
    }

    public function uninstall()
    {
        $return = ['error' => 0, 'msg' => ''];
        //Please delete all values inserted in the installation step
        DB::connection(GP247_DB_CONNECTION)->beginTransaction();
        try {
            (new AdminConfig)
            ->where('key', $this->configKey)
            ->orWhere('code', $this->configKey.'_config')
            ->delete();

            //Admin config home
            AdminHome::where('extension', $this->appPath)->delete();

            (new ExtensionModel)->uninstallExtension();

            DB::connection(GP247_DB_CONNECTION)->commit();
            $return = ['error' => 0, 'msg' => 'Uninstall success'];
        } catch (\Throwable $e) {
            DB::connection(GP247_DB_CONNECTION)->rollBack();
            $return = ['error' => 1, 'msg' => $e->getMessage()];
        }

        return $return;
    }
    
    public function enable()
    {
        $return = ['error' => 0, 'msg' => ''];
        $process = (new AdminConfig)
            ->where('group', $this->configGroup)
            ->where('key', $this->configKey)
            ->update(['value' => self::ON]);
        //Admin config home
        AdminHome::where('extension', $this->appPath)->update(['status' => 1]);

        if (!$process) {
            $return = ['error' => 1, 'msg' => 'Error enable'];
        }
        return $return;
    }

    public function disable()
    {
        $return = ['error' => 0, 'msg' => ''];
        $process = (new AdminConfig)
            ->where('group', $this->configGroup)
            ->where('key', $this->configKey)
            ->update(['value' => self::OFF]);
        if (!$process) {
            $return = ['error' => 1, 'msg' => 'Error disable'];
        }

        //Admin config home
        AdminHome::where('extension', $this->appPath)->update(['status' => 0]);

        return $return;
    }

    // Process when click button plugin in admin    
    
    public function clickApp()
    {
        //
    }

    /**
     * Get info plugin
     *
     * @return  [type]  [return description]
     */
    public function getInfo()
    {
        $arrData = [
            'title' => $this->title,
            'key' => $this->configKey,
            'image' => $this->image,
            'permission' => self::ALLOW,
            'version' => $this->version,
            'auth' => $this->auth,
            'link' => $this->link,
            'value' => 0, // this return need for plugin shipping
            'appPath' => $this->appPath
        ];

        return $arrData;
    }
}
