<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminStore;
use GP247\Core\Models\AdminLanguage;
use Validator;

class AdminStoreMaintainController extends RootAdminController
{
    public $languages;

    public function __construct()
    {
        parent::__construct();
        $this->languages = AdminLanguage::getListActive();
    }

    /**
     * Form edit
     */
    public function index()
    {
        $id = session('adminStoreId');
        $maintain = AdminStore::find($id);
        if ($maintain === null) {
            return redirect(gp247_route_admin('admin_store_maintain.index'))->with('error', gp247_language_render('display.data_not_found'));
            return gp247_language_render('display.data_not_found_detail');
        }
        $data = [
            'title' => gp247_language_render('admin.maintain.title'),
            'subTitle' => '',
            'title_description' => '',
            'languages' => $this->languages,
            'maintain' => $maintain,
            'url_action' => gp247_route_admin('admin_store_maintain.index'),
        ];
        return view('gp247-core::screen.store_maintain')
            ->with($data);
    }

    /**
     * update status
     */
    public function postEdit()
    {
        $id = session('adminStoreId');
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'descriptions.*.maintain_content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        //Edit
        foreach ($data['descriptions'] as $code => $row) {
            $dataUpdate = [
                'storeId' => $id,
                'lang' => $code,
                'name' => 'maintain_content',
                'value' => $row['maintain_content'],
            ];
            AdminStore::updateDescription($dataUpdate);

            $dataUpdate = [
                'storeId' => $id,
                'lang' => $code,
                'name' => 'maintain_note',
                'value' => $row['maintain_note'],
            ];
            AdminStore::updateDescription($dataUpdate);
        }
//
        return redirect()->route('admin_store_maintain.index')->with('success', gp247_language_render('action.edit_success'));
    }
}
