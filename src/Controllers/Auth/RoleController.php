<?php
namespace GP247\Core\Controllers\Auth;

use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Core\Controllers\RootAdminController;
use Validator;

class RoleController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }
    
    public function index()
    {
        $data = [
            'title' => gp247_language_render('admin.role.list'),
            'subTitle' => '',
            'urlDeleteItem' => gp247_route_admin('admin_role.delete'),
            'removeList' => 0, // 1 - Enable function delete list item
            'buttonRefresh' => 1, // 1 - Enable button refresh
        ];

        $listTh = [
            'id' => 'ID',
            'name' => gp247_language_render('admin.role.name'),
            'slug' => gp247_language_render('admin.role.slug'),
            'permission' => gp247_language_render('admin.role.permission'),
            'created_at' => gp247_language_render('admin.role.created_at'),
            'updated_at' => gp247_language_render('admin.updated_at'),
            'action' => gp247_language_render('action.title'),
        ];
        $sort = gp247_clean(request('sort') ?? 'id_desc');
        $arrSort = [
            'id__desc' => gp247_language_render('filter_sort.id_desc'),
            'id__asc' => gp247_language_render('filter_sort.id_asc'),
            'name__desc' => gp247_language_render('filter_sort.name_desc'),
            'name__asc' => gp247_language_render('filter_sort.name_asc'),
        ];
        $obj = new AdminRole;
        if ($sort && array_key_exists($sort, $arrSort)) {
            $field = explode('__', $sort)[0];
            $sort_field = explode('__', $sort)[1];
            $obj = $obj->orderBy($field, $sort_field);
        } else {
            $obj = $obj->orderBy('id', 'desc');
        }
        $dataTmp = $obj->paginate(20);

        $dataTr = [];
        foreach ($dataTmp as $key => $row) {
            $showPermission = '';
            if ($row['permissions']->count()) {
                foreach ($row['permissions'] as $key => $p) {
                    $showPermission .= '<span class="badge badge-success"">' . $p->name . '</span> ';
                }
            }

            if (!in_array($row['id'], GP247_GUARD_ROLES)) {
                $arrAction = [
                    '<a href="' . gp247_route_admin('admin_role.edit', ['id' => $row['id'], 'page' => request('page')]) . '"  class="dropdown-item"><i class="fa fa-edit"></i> '.gp247_language_render('action.edit').'</a>',
                    '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>',
                    ];
            } else {
                $arrAction = [];
            }
            $action = $this->procesListAction($arrAction);

            $dataTr[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'permission' => $showPermission,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'action' => $action,
            ];
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
        $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);

        //menuRight
        $data['menuRight'][] = '<a href="' . gp247_route_admin('admin_role.create') . '" class="btn btn-sm  btn-success  btn-flat" title="New" id="button_create_new">
                           <i class="fa fa-plus" title="'.gp247_language_render('action.add').'"></i>
                           </a>';
        //=menuRight

        //menuSort
        $optionSort = '';
        foreach ($arrSort as $kSort => $vSort) {
            $optionSort .= '<option  ' . (($sort == $kSort) ? "selected" : "") . ' value="' . $kSort . '">' . $vSort . '</option>';
        }
        //=menuSort


        //topMenuRight
        $data['topMenuRight'][] ='
                <form action="' . gp247_route_admin('admin_role.index') . '" id="button_search">
                <div class="input-group input-group float-left">
                    <select class="form-control form-control-sm rounded-0 select2" name="sort" id="sort">
                    '.$optionSort.'
                    </select>
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
                </form>';
        //=topMenuRight

        return view('gp247-core::screen.list')
            ->with($data);
    }

    /**
     * Form create new item in admin
     * @return [type] [description]
     */
    public function create()
    {
        $data = [
            'title' => gp247_language_render('admin.role.add_new_title'),
            'subTitle' => '',
            'title_description' => gp247_language_render('admin.role.add_new_des'),
            'role' => [],
            'permission' => (new AdminPermission)->pluck('name', 'id')->all(),
            'userList' => (new AdminUser)->pluck('name', 'id')->all(),
            'url_action' => gp247_route_admin('admin_role.post_create'),

        ];

        return view('gp247-core::auth.role')
            ->with($data);
    }

    /**
     * Post create new item in admin
     * @return [type] [description]
     */
    public function postCreate()
    {
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'name' => 'required|string|max:50|unique:"'.AdminRole::class.'",name',
            'slug' => 'required|regex:/(^([0-9A-Za-z\._\-]+)$)/|unique:"'.AdminRole::class.'",slug|string|max:50|min:3',
        ], [
            'slug.regex' => gp247_language_render('admin.role.slug_validate'),
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $dataCreate = [
            'name' => $data['name'],
            'slug' => $data['slug'],
        ];
        $dataCreate = gp247_clean($dataCreate, [], true);
        $role = AdminRole::createRole($dataCreate);
        $permission = $data['permission'] ?? [];
        $administrators = $data['administrators'] ?? [];
        //Insert permission
        if ($permission) {
            $role->permissions()->attach($permission);
        }
        //Insert administrators
        if ($administrators) {
            $role->administrators()->attach($administrators);
        }
        return redirect()->route('admin_role.index')->with('success', gp247_language_render('action.create_success'));
    }

    /**
     * Form edit
     */
    public function edit($id)
    {
        $role = AdminRole::find($id);
        if ($role === null) {
            return redirect(gp247_route_admin('admin_role.index'))->with('error', gp247_language_render('display.data_not_found'));
        }
        $data = [
            'title' => gp247_language_render('action.edit'),
            'subTitle' => '',
            'title_description' => '',
            'role' => $role,
            'permission' => (new AdminPermission)->pluck('name', 'id')->all(),
            'userList' => (new AdminUser)->pluck('name', 'id')->all(),
            'url_action' => gp247_route_admin('admin_role.post_edit', ['id' => $role['id']]),
        ];
        return view('gp247-core::auth.role')
            ->with($data);
    }

    /**
     * update status
     */
    public function postEdit($id)
    {
        $role = AdminRole::find($id);
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'name' => 'required|string|max:50|unique:"'.AdminRole::class.'",name,' . $role->id . '',
            'slug' => 'required|regex:/(^([0-9A-Za-z\._\-]+)$)/|unique:"'.AdminRole::class.'",slug,' . $role->id . '|string|max:50|min:3',
        ], [
            'slug.regex' => gp247_language_render('admin.role.slug_validate'),
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        //Edit

        $dataUpdate = [
            'name' => $data['name'],
            'slug' => $data['slug'],
        ];
        $dataUpdate = gp247_clean($dataUpdate, [], true);
        $role->update($dataUpdate);
        $permission = $data['permission'] ?? [];
        $administrators = $data['administrators'] ?? [];
        $role->permissions()->detach();
        $role->administrators()->detach();
        //Insert permission
        if ($permission) {
            $role->permissions()->attach($permission);
        }
        //Insert administrators
        if ($administrators) {
            $role->administrators()->attach($administrators);
        }
        return redirect()->route('admin_role.index')->with('success', gp247_language_render('action.edit_success'));
    }

    /*
    Delete list Item
    Need mothod destroy to boot deleting in model
     */
    public function deleteList()
    {
        if (!request()->ajax()) {
            return response()->json(['error' => 1, 'msg' => gp247_language_render('admin.method_not_allow')]);
        } else {
            $ids = request('ids');
            $arrID = explode(',', $ids);
            $arrID = array_diff($arrID, GP247_GUARD_ROLES);
            AdminRole::destroy($arrID);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
        }
    }
}
