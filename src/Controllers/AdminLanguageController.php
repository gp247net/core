<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminLanguage;
use Validator;

class AdminLanguageController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }
    
    public function index()
    {
        $data = $this->processDataScreen();
        $data['title'] = gp247_language_render('admin.language.list');
        $data['title_action'] = '<i class="fa fa-plus" aria-hidden="true"></i> ' . gp247_language_render('admin.language.add_new_title');
        $data['url_action'] = gp247_route_admin('admin_language.create');
        $data['layout'] = 'index';

        return view('gp247-core::screen.language')
            ->with($data);
    }

    /**
     * Post create
     * @return [type] [description]
     */
    public function postCreate()
    {
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'icon' => 'required',
            'sort' => 'numeric|min:0',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:10|unique:"'.AdminLanguage::class.'",code',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $dataCreate = [
            'icon' => $data['icon'],
            'name' => $data['name'],
            'code' => $data['code'],
            'rtl' => empty($data['rtl']) ? 0 : 1,
            'status' => empty($data['status']) ? 0 : 1,
            'sort' => (int) $data['sort'],
        ];
        $dataCreate = gp247_clean($dataCreate, [], true);
        $obj = AdminLanguage::create($dataCreate);

        return redirect()->route('admin_language.edit', ['id' => $obj['id']])->with('success', gp247_language_render('action.create_success'));
    }

    /**
     * Form edit
     */
    public function edit($id)
    {
        $language = AdminLanguage::find($id);
        if (!$language) {
            return redirect(gp247_route_admin('admin_language.index'))->with('error', gp247_language_render('display.data_not_found'));
        }
        $data = $this->processDataScreen($id);
        $data['title'] = gp247_language_render('admin.language.list');
        $data['title_action'] = '<i class="fa fa-edit" aria-hidden="true"></i> ' . gp247_language_render('action.edit');
        $data['url_action'] = gp247_route_admin('admin_language.post_edit', ['id' => $language['id']]);
        $data['language'] = $language;
        $data['layout'] = 'edit';
        
        return view('gp247-core::screen.language')
        ->with($data);
    }

    /**
     * update
     */
    public function postEdit($id)
    {
        $language = AdminLanguage::find($id);
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'icon' => 'required',
            'name' => 'required',
            'sort' => 'numeric|min:0',
            'code' => 'required|string|max:10|unique:"'.AdminLanguage::class.'",code,' . $language->id . ',id',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        //Edit

        $dataUpdate = [
            'icon' => $data['icon'],
            'name' => $data['name'],
            'code' => $data['code'],
            'rtl' => empty($data['rtl']) ? 0 : 1,
            'sort' => (int)$data['sort'],
        ];
        //Check status before change, sure have one language is default
        $check = AdminLanguage::where('status', 1)->where('code', '<>', $data['code'])->count();
        if ($check) {
            $dataUpdate['status'] = empty($data['status']) ? 0 : 1;
        } else {
            $dataUpdate['status'] = 1;
        }
        //End check status
        $obj = AdminLanguage::find($id);
        $dataUpdate =  gp247_clean($dataUpdate, [], true);
        $obj->update($dataUpdate);

        return redirect()->back()->with('success', gp247_language_render('action.edit_success'));
    }

    private function processDataScreen(string $id = null) {
        $data = [
            'subTitle' => '',
            'icon' => 'fa fa-tasks',
            'urlDeleteItem' => gp247_route_admin('admin_language.delete'),
            'removeList' => 0, // 1 - Enable function delete list item
            'buttonRefresh' => 0, // 1 - Enable button refresh
        ];
    
        $listTh = [
            'name' => gp247_language_render('admin.language.name'),
            'code' => gp247_language_render('admin.language.code'),
            'icon' => gp247_language_render('admin.language.icon'),
            'rtl' => gp247_language_render('admin.language.layout_rtl'),
            'sort' => gp247_language_render('admin.language.sort'),
            'status' => gp247_language_render('admin.language.status'),
            'action' => gp247_language_render('action.title'),
        ];
            $obj = new AdminLanguage;
            $obj = $obj->orderBy('id', 'desc');
            $dataTmp = $obj->paginate(20);
    
            $dataTr = [];
            foreach ($dataTmp as $key => $row) {
                $arrAction = [
                '<a href="' . gp247_route_admin('admin_language.edit', ['id' => $row['id'], 'page' => request('page')]) . '"  class="dropdown-item"><i class="fa fa-edit"></i> '.gp247_language_render('action.edit').'</a>',
                ];
                if (!in_array($row['id'], GP247_GUARD_LANGUAGE)) {
                    $arrAction[] = '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>';
                }
    
                $action = $this->procesListAction($arrAction);
    
                $dataTr[$row['id']] = [
                'name' => $row['name'],
                'code' => $row['code'],
                'icon' => gp247_image_render($row['icon'], '30px', '30px', $row['name']),
                'rtl' => $row['rtl'],
                'sort' => $row['sort'],
                'status' => $row['status'] ? '<span class="badge badge-success">ON</span>' : '<span class="badge badge-danger">OFF</span>',
                'action' => $action,
            ];
            }
    
            $data['listTh'] = $listTh;
            $data['dataTr'] = $dataTr;
            $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
            $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);
            return $data;
    }


    /*
        Delete list item
        Need mothod destroy to boot deleting in model
     */
    public function deleteList()
    {
        if (!request()->ajax()) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.method_not_allow')]);
        } else {
            $ids = request('ids');
            $arrID = explode(',', $ids);
            $arrID = array_diff($arrID, GP247_GUARD_LANGUAGE);
            AdminLanguage::destroy($arrID);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
        }
    }
}
