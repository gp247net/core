<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminPage;

class AdminStoreConfigController extends RootAdminController
{
    public $templates;
    public $languages;
    public $timezones;

    public function __construct()
    {
        parent::__construct();
        foreach (timezone_identifiers_list() as $key => $value) {
            $timezones[$value] = $value;
        }
        $this->templates = gp247_extension_get_installed(type: 'Templates', active: true);
        $this->languages = AdminLanguage::getListActive();
        $this->timezones = $timezones;
    }

    public function index()
    {
        $id = session('adminStoreId');
        $data = [
            'title' => gp247_language_render('admin.menu_titles.config_store_default'),
            'subTitle' => '',
        ];

        $configCaptchaQuery = [
            'code' => 'captcha_config',
            'storeId' => $id,
            'keyBy' => 'key',
        ];
        $configCaptcha = AdminConfig::getListConfigByCode($configCaptchaQuery);

        $configCustomizeQuery = [
            'code' => 'admin_custom_config',
            'storeId' => $id,
            'keyBy' => 'key',
        ];
        $configCustomize = AdminConfig::getListConfigByCode($configCustomizeQuery);

        $emailConfigQuery = [
            'code' => ['smtp_config', 'email_action'],
            'storeId' => $id,
            'groupBy' => 'code',
            'sort'    => 'asc',
        ];
        $emailConfig = AdminConfig::getListConfigByCode($emailConfigQuery);

        $data['emailConfig'] = $emailConfig;
        $data['smtp_method'] = ['' => 'None Secirity', 'TLS' => 'TLS', 'SSL' => 'SSL'];
        $data['captcha_page'] = [
            'register' => gp247_language_render('admin.captcha.captcha_page_register'),
            'forgot'   => gp247_language_render('admin.captcha.captcha_page_forgot_password'),
            'checkout' => gp247_language_render('admin.captcha.captcha_page_checkout'),
            'contact'  => gp247_language_render('admin.captcha.captcha_page_contact'),
            'review'   => gp247_language_render('admin.captcha.captcha_page_review'),
        ];
        //End email
        $data['pluginCaptchaInstalled']         = gp247_captcha_get_plugin_installed();
        $data['configCaptcha']                  = $configCaptcha;
        $data['configCustomize']                = $configCustomize;
        $data['templates']                      = $this->templates;
        $data['timezones']                      = $this->timezones;
        $data['languages']                      = $this->languages;
        $data['storeId']                        = $id;
        $data['urlUpdateConfig']                = gp247_route_admin('admin_config.update');
        $data['urlUpdateConfigGlobal']          = gp247_route_admin('admin_config_global.update');

        return view('gp247-core::screen.config_store_default')
        ->with($data);
    }

    /*
    Update value config store
    */
    public function update()
    {
        if (!request()->ajax()) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.method_not_allow')]);
        } else {
            $data = request()->all();
            $name = $data['name'];
            $value = $data['value'];
            $storeId = $data['storeId'] ?? '';
            if (!$storeId) {
                return response()->json(
                    [
                    'error' => 1,
                    'field' => 'storeId',
                    'value' => $storeId,
                    'msg'   => 'Store ID can not empty!',
                    ]
                );
            }

            try {
                AdminConfig::where('key', $name)
                    ->where('store_id', $storeId)
                    ->update(['value' => $value]);
                $error = 0;
                $msg = gp247_language_render('action.update_success');
            } catch (\Throwable $e) {
                $error = 1;
                $msg = $e->getMessage();
            }
            return response()->json(
                [
                'error' => $error,
                'field' => $name,
                'value' => $value,
                'msg'   => $msg,
                ]
            );
        }
    }

    /**
     * Add new config admin
     *
     * @return  [type]  [return description]
     */
    public function addNew() {
        $data = request()->all();
        $key = $data['key'] ?? '';
        $value = $data['value'] ?? '';
        $detail = $data['detail'] ?? '';
        $storeId = $data['storeId'] ?? '';

        if (session('adminStoreId') != GP247_STORE_ID_ROOT && $storeId != session('adminStoreId')) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.remove_dont_permisison') . ': storeId#' . $storeId]);
        }

        if (!$key) {
            return redirect()->back()->with('error', 'Key: '.gp247_language_render('admin.not_empty'));
        }
        $group = $data['group'] ?? 'admin_custom_config';
        $dataUpdate = ['key' => $key, 'value' => $value, 'code' => $group, 'store_id' => $storeId, 'detail' => $detail];
        if (AdminConfig::where(['key' => $key, 'store_id' => $storeId])->first()) {
            return redirect()->back()->with('error', gp247_language_quickly('admin.admin_custom_config.key_exist', 'Key already exist'));
        }
        $dataUpdate = gp247_clean($dataUpdate, [], true);
        AdminConfig::insert($dataUpdate);
        return redirect()->back()->with('success', gp247_language_render('action.update_success'));
    }

    /**
     * Remove config
     *
     * @return  [type]  [return description]
     */
    public function delete() {
        $key = request('key');
        $storeId = request('storeId');

        if (session('adminStoreId') != GP247_STORE_ID_ROOT && $storeId != session('adminStoreId')) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.remove_dont_permisison') . ': storeId#' . $storeId]);
        }
        AdminConfig::where('key', $key)->where('store_id', $storeId)->delete();
        return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
    }
}
