<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminHome;
use Validator;
use Illuminate\Support\Facades\View;

class AdminHomeLayoutController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }
    
    public function index()
    {
        $data = $this->processDataScreen();
        $data['title'] = gp247_language_render('admin.admin_home_layout.list');
        $data['title_action'] = '<i class="fa fa-plus" aria-hidden="true"></i> ' . gp247_language_render('admin.admin_home_layout.add_new_title');
        $data['url_action'] = gp247_route_admin('admin_home_layout.create');
        $data['layout'] = 'index';

        return view('gp247-core::screen.home_layout')
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
            'size' => 'numeric|min:1|max:12',
            'sort' => 'numeric|min:0',
            'view' => 'required|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        $viewTmp = explode('::', $data['view']);
        $extension = $viewTmp[0];
        $dataCreate = [
            'view' => $data['view'],
            'size' => $data['size'],
            'extension' => $extension,
            'status' => empty($data['status']) ? 0 : 1,
            'sort' => (int) $data['sort'],
        ];
        $dataCreate = gp247_clean($dataCreate, [], true);
        $obj = AdminHome::create($dataCreate);

        return redirect()->route('admin_home_layout.edit', ['id' => $obj['id']])->with('success', gp247_language_render('action.create_success'));
    }

    /**
     * Form edit
     */
    public function edit($id)
    {
        $block = AdminHome::find($id);
        if (!$block) {
            return redirect(gp247_route_admin('admin_home_layout.index'))->with('error', gp247_language_render('display.data_not_found'));
        }
        $data = $this->processDataScreen($id);
        $data['title_action'] = '<i class="fa fa-edit" aria-hidden="true"></i> ' . gp247_language_render('action.edit');
        $data['url_action'] = gp247_route_admin('admin_home_layout.post_edit', ['id' => $block['id']]);
        $data['block'] = $block;
        $data['layout'] = 'edit';

        $data['layout'] = 'edit';
        return view('gp247-core::screen.home_layout')
        ->with($data);
    }

    /**
     * update
     */
    public function postEdit($id)
    {
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'size' => 'numeric|min:1|max:12',
            'sort' => 'numeric|min:0',
            'view' => 'required|string',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        //Edit

        $dataUpdate = [
            'view' => $data['view'],
            'size' => $data['size'],
            'sort' => (int)$data['sort'],
            'status' => empty($data['status']) ? 0 : 1,
        ];

        $obj = AdminHome::find($id);
        $dataUpdate =  gp247_clean($dataUpdate, [], true);
        $obj->update($dataUpdate);

        return redirect()->back()->with('success', gp247_language_render('action.edit_success'));
    }

    private function processDataScreen(string $id = null) {
        $data = [
            'subTitle' => '',
            'urlDeleteItem' => gp247_route_admin('admin_home_layout.delete'),
        ];

        $listTh = [
            'view' => gp247_language_render('admin.admin_home_layout.view'),
            'size' => gp247_language_render('admin.admin_home_layout.size'),
            'sort' => gp247_language_render('admin.admin_home_layout.sort'),
            'status' => gp247_language_render('admin.admin_home_layout.status'),
            'view_status' => gp247_language_render('admin.admin_home_layout.view_status'),
            'action' => gp247_language_render('action.title'),
        ];
        $obj = new AdminHome;
        $obj = $obj->orderBy('created_at', 'desc');
        $dataTmp = $obj->paginate(20);

        $dataTr = [];
        foreach ($dataTmp as $key => $row) {
            $arrAction = [
            '<a href="' . gp247_route_admin('admin_home_layout.edit', ['id' => $row['id'], 'page' => request('page')]) . '"  class="dropdown-item"><i class="fa fa-edit"></i> '.gp247_language_render('action.edit').'</a>',
            ];
            $arrAction[] = '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>';

            $action = $this->procesListAction($arrAction);

            if (View::exists($row['view'])) {
                $view_status = 1;
            } else {
                $view_status = 0;
            }
            $dataTr[$row['id']] = [
                'view' => $row['view'],
                'size' => $row['size'],
                'sort' => $row['sort'],
                'status' => $row['status'] ? '<span class="badge badge-success">ON</span>' : '<span class="badge badge-danger">OFF</span>',
                'view_status' => $view_status ? '<span class="badge badge-success">OK</span>' : '<span class="badge badge-danger">'.gp247_language_render('display.data_not_found').'</span>',
                'action' => $action,
            ];
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['listView'] = collect(config('gp247-module.homepage'))->pluck('view')->toArray();
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
            AdminHome::destroy($arrID);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
        }
    }
}
