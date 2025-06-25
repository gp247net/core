<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminStore;
use GP247\Core\Models\AdminLanguage;

class AdminStoreInfoController extends RootAdminController
{
    public $templates;
    public $languages;

    public function __construct()
    {
        parent::__construct();
        // Check function exist
        if (!function_exists('gp247_front_get_all_template_installed')) {
            $this->templates = [];
        } else {
            $this->templates = gp247_front_get_all_template_installed();
        }
        $this->languages = AdminLanguage::getListActive();
    }

    public function index()
    {
        $id = session('adminStoreId');
        $store = AdminStore::find($id);
        if (!$store) {
            $data = [
                'title' => gp247_language_render('admin.store.title'),
                'subTitle' => '',
                'dataNotFound' => 1
            ];
            return view('gp247-core::screen.store_info')
            ->with($data);
        }
        $data = [
            'title' => gp247_language_render('admin.store.title'),
            'subTitle' => '',
        ];
        $data['store'] = $store;
        $data['templates'] = $this->templates;
        $data['languages'] = $this->languages;
        $data['storeId'] = $id;

        return view('gp247-core::screen.store_info')
        ->with($data);
    }



    /*
    Update value config
    */
    public function updateInfo()
    {
        $data      = request()->all();
        $data = gp247_clean($data, [], true);
        $storeId   = $data['storeId'];
        $fieldName = $data['name'];
        $value     = $data['value'];
        $parseName = explode('__', $fieldName);
        $name      = $parseName[0];
        $lang      = $parseName[1] ?? '';
        $msg       = 'Update success';
        // Check store
        $store     = AdminStore::find($storeId);
        if (!$store) {
            return response()->json(['error' => 1, 'msg' => 'Store not found!']);
        }

        if (!$lang) {
            try {
                if ($name == 'type') {
                    // Can not change type in here
                    $error = 1;
                    $msg = gp247_language_render('admin.store.value_cannot_change');
                } elseif ($name == 'domain') {
                    if (
                        $storeId == GP247_STORE_ID_ROOT 
                        || ((gp247_store_check_multi_partner_installed()) && gp247_store_is_partner($storeId)) 
                        || gp247_store_check_multi_store_installed()
                    ) {
                        // Only store root can edit domain
                        $domain = gp247_store_process_domain($value);
                        if (AdminStore::where('domain', $domain)->where('id', '<>', $storeId)->first()) {
                            $error = 1;
                            $msg = gp247_language_render('admin.store.domain_exist');
                        } else {
                            AdminStore::where('id', $storeId)->update([$name => $domain]);
                            $error = 0;
                        }
                    } else {
                        $error = 1;
                        $msg = gp247_language_render('admin.store.value_cannot_change');
                    }
                } elseif ($name == 'code') {
                    if (AdminStore::where('code', $value)->where('id', '<>', $storeId)->first()) {
                        $error = 1;
                        $msg = gp247_language_render('admin.store.code_exist');
                    } else {
                        AdminStore::where('id', $storeId)->update([$name => $value]);
                        $error = 0;
                    }
                } elseif ($name == 'template') {
                    
                    $store = AdminStore::where('id', $storeId)->first();
                    $templateKey = $value;
                    $oldTepmlateKey = $store->template;
                    
                    $classTemplate = gp247_extension_get_namespace(type:'Templates', key:$templateKey);
                    $classTemplate = $classTemplate . '\AppConfig';
                    $oldClassTemplate = gp247_extension_get_namespace(type:'Templates', key:$oldTepmlateKey);
                    $oldClassTemplate = $oldClassTemplate . '\AppConfig';
                    // Check class exist
                    if (class_exists($oldClassTemplate)) {
                        (new $oldClassTemplate)->removeStore($storeId);
                    }
                    if (class_exists($classTemplate)) {
                        (new $classTemplate)->setupStore($storeId);
                    }

                    AdminStore::where('id', $storeId)->update([$name => $templateKey]);

                    gp247_notice_add(type:'template', typeId: $templateKey, content:'admin_notice.gp247_template_change::old__'.$oldTepmlateKey.'::new__'.$templateKey);
                    gp247_extension_after_update();

                    $error = 0;
                } else {
                    AdminStore::where('id', $storeId)->update([$name => $value]);
                    $error = 0;
                }
            } catch (\Throwable $e) {
                $error = 1;
                $msg = $e->getMessage();
            }
        } else {
            // Process description
            $dataUpdate = [
                'storeId' => $storeId,
                'lang' => $lang,
                'name' => $name,
                'value' => $value,
            ];
            $dataUpdate = gp247_clean($dataUpdate, [], true);
            try {
                AdminStore::updateDescription($dataUpdate);
                $error = 0;
            } catch (\Throwable $e) {
                $error = 1;
                $msg = $e->getMessage();
            }
        }
        return response()->json(['error' => $error, 'msg' => $msg]);
    }

}
