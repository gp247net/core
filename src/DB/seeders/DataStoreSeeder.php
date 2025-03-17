<?php

namespace GP247\Core\DB\seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DataStoreSeeder extends Seeder
{
    public function getTemplateDefault() {
        return  empty(session('lastStoreTemplate')) ? (defined('GP247_TEMPLATE_FRONT_DEFAULT') ? GP247_TEMPLATE_FRONT_DEFAULT : 'Default') : session('lastStoreTemplate');
    }
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $storeId = empty(session('lastStoreId')) ? GP247_STORE_ID_ROOT : session('lastStoreId');

        $db = DB::connection(GP247_DB_CONNECTION);

        $dataConfig = $this->dataConfig($storeId);
        $db->table(GP247_DB_PREFIX.'admin_config')->insert($dataConfig);
    }
    
    public function dataConfig($storeId) {
        $dataConfig = [
            ['group' => '','code' => 'email_action','key' => 'email_action_mode','value' => '0','sort' => '0','detail' => 'email.email_action.email_action_mode','store_id' => $storeId],
            ['group' => '','code' => 'email_action','key' => 'email_action_queue','value' => '0','sort' => '1','detail' => 'email.email_action.email_action_queue','store_id' => $storeId],
            ['group' => '','code' => 'smtp_config','key' => 'smtp_host','value' => '','sort' => '1','detail' => 'email.config_smtp.smtp_host','store_id' => $storeId],
            ['group' => '','code' => 'smtp_config','key' => 'smtp_user','value' => '','sort' => '2','detail' => 'email.config_smtp.smtp_user','store_id' => $storeId],
            ['group' => '','code' => 'smtp_config','key' => 'smtp_password','value' => '','sort' => '3','detail' => 'email.config_smtp.smtp_password','store_id' => $storeId],
            ['group' => '','code' => 'smtp_config','key' => 'smtp_security','value' => '','sort' => '4','detail' => 'email.config_smtp.smtp_security','store_id' => $storeId],
            ['group' => '','code' => 'smtp_config','key' => 'smtp_port','value' => '','sort' => '5','detail' => 'email.config_smtp.smtp_port','store_id' => $storeId],
            ['group' => '','code' => 'smtp_config','key' => 'smtp_name','value' => '','sort' => '6','detail' => 'email.config_smtp.smtp_name','store_id' => $storeId],
            ['group' => '','code' => 'smtp_config','key' => 'smtp_from','value' => '','sort' => '7','detail' => 'email.config_smtp.smtp_from','store_id' => $storeId],
            ['group' => '','code' => 'admin_custom_config','key' => 'facebook_url','value' => 'https://www.facebook.com/GP247.official/','sort' => '0','detail' => 'admin.admin_custom_config.facebook_url','store_id' => $storeId],
            ['group' => '','code' => 'admin_custom_config','key' => 'fanpage_url','value' => 'https://www.facebook.com/GP247.official/','sort' => '0','detail' => 'admin.admin_custom_config.fanpage_url','store_id' => $storeId],
            ['group' => '','code' => 'admin_custom_config','key' => 'twitter_url','value' => '#','sort' => '0','detail' => 'admin.admin_custom_config.twitter_url','store_id' => $storeId],
            ['group' => '','code' => 'admin_custom_config','key' => 'instagram_url','value' => '#','sort' => '0','detail' => 'admin.admin_custom_config.instagram_url','store_id' => $storeId],
            ['group' => '','code' => 'admin_custom_config','key' => 'youtube_url','value' => 'https://www.youtube.com/channel/UCR8kitefby3N6KvvawQVqdg/videos','sort' => '0','detail' => 'admin.admin_custom_config.youtube_url','store_id' => $storeId],
        ];
        return $dataConfig;
    }
}
