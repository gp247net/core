<?php
namespace GP247\Core\Controllers\Auth;

use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Controllers\PasswordValidationTrait;
use Validator;

class UsersController extends RootAdminController
{
    use PasswordValidationTrait;
    public $permissions;
    public $roles;
    public function __construct()
    {
        parent::__construct();
        $this->permissions = AdminPermission::pluck('name', 'id')->all();
        $this->roles       = AdminRole::pluck('name', 'id')->all();
    }

    public function index()
    {
        $data = [
            'title'         => gp247_language_render('admin.user.list'),
            'subTitle'      => '',
            'urlDeleteItem' => gp247_route_admin('admin_user.delete'),
            'removeList'    => 0, // 1 - Enable function delete list item
            'buttonRefresh' => 1, // 1 - Enable button refresh
        ];
        $listTh = [
            'id'         => 'ID',
            'username'   => gp247_language_render('admin.user.user_name'),
            'name'       => gp247_language_render('admin.user.name'),
            'roles'      => gp247_language_render('admin.user.roles'),
            'permission' => gp247_language_render('admin.user.permission'),
            'status' => gp247_language_render('admin.user.status'),
            'created_at' => gp247_language_render('admin.created_at'),
            'action'     => gp247_language_render('action.title'),
        ];
        $sort = gp247_clean(request('sort') ?? 'id_desc');
        $keyword    = gp247_clean(request('keyword') ?? '');
        $arrSort = [
            'id__desc'       => gp247_language_render('filter_sort.id_desc'),
            'id__asc'        => gp247_language_render('filter_sort.id_asc'),
            'name__desc'     => gp247_language_render('filter_sort.name_desc'),
            'name__asc'      => gp247_language_render('filter_sort.name_asc'),
        ];
        $obj = new AdminUser;

        if ($keyword) {
            $obj = $obj->whereRaw('id = ?  OR name like "%' . $keyword . '%" OR username like "%' . $keyword . '%"  ', [$keyword]);
        }
        if ($sort && array_key_exists($sort, $arrSort)) {
            $field = explode('__', $sort)[0];
            $sort_field = explode('__', $sort)[1];
            $obj = $obj->orderBy($field, $sort_field);
        } else {
            $obj = $obj->orderBy('created_at', 'desc');
        }
        $dataTmp = $obj->paginate(20);

        $dataTr = [];
        foreach ($dataTmp as $key => $row) {
            $showRoles = '';
            if ($row['roles']->count()) {
                foreach ($row['roles'] as $key => $rols) {
                    $showRoles .= '<span class="badge badge-success">' . $rols->name . '</span> ';
                }
            }
            $showPermission = '';
            if ($row['permissions']->count()) {
                foreach ($row['permissions'] as $key => $p) {
                    $showPermission .= '<span class="badge badge-success">' . $p->name . '</span> ';
                }
            }

            $arrAction = [
                '<a href="' . gp247_route_admin('admin_user.edit', ['id' => $row['id'], 'page' => request('page')]) . '"  class="dropdown-item"><i class="fa fa-edit"></i> '.gp247_language_render('action.edit').'</a>',
                ];
            if (admin()->user()->id == $row['id'] || in_array($row['id'], GP247_GUARD_ADMIN)) {
                //
            } else {
                $arrAction[] = '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>';
            }
            $action = $this->procesListAction($arrAction);


            $dataTr[] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'name' => $row['name'],
                'roles' => $showRoles,
                'permission' => $showPermission,
                'status' => $row['status'] ? '<span class="badge badge-success">'.gp247_language_render('admin.user.active').'</span>' : '<span class="badge badge-danger">'.gp247_language_render('admin.user.inactive').'</span>',
                'created_at' => $row['created_at'],
                'action' => $action,
            ];
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
        $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);

        //menuRight
        $data['menuRight'][] = '<a href="' . gp247_route_admin('admin_user.create') . '" class="btn btn-sm  btn-success  btn-flat" title="New" id="button_create_new">
                           <i class="fa fa-plus" title="'.gp247_language_render('action.add').'"></i>
                           </a>';
        //=menuRight

        //menuSort
        $optionSort = '';
        foreach ($arrSort as $kSort => $vSort) {
            $optionSort .= '<option  ' . (($sort == $kSort) ? "selected" : "") . ' value="' . $kSort . '">' . $vSort . '</option>';
        }
        //=menuSort

        //menuSearch
        $data['topMenuRight'][] = '
                <form action="' . gp247_route_admin('admin_user.index') . '" id="button_search">
                <div class="input-group input-group" style="width: 350px;">
                    <select class="form-control form-control-sm rounded-0 select2" name="sort" id="sort">
                    '.$optionSort.'
                    </select> &nbsp;
                    <input type="text" name="keyword" class="form-control form-control-sm rounded-0 float-right" placeholder="' . gp247_language_render('admin.user.search_place') . '" value="' . $keyword . '">
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
                </form>';
        //=menuSearch


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
            'title'             => gp247_language_render('admin.user.add_new_title'),
            'subTitle'          => '',
            'title_description' => gp247_language_render('admin.user.add_new_des'),
            'user'              => [],
            'roles'             => $this->roles,
            'permissions'       => $this->permissions,
            'url_action'        => gp247_route_admin('admin_user.post_create'),
        ];

        return view('gp247-core::auth.user')
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
            'name'     => 'required|string|max:100',
            'username' => 'required|regex:/(^([0-9A-Za-z@\._]+)$)/|unique:"'.AdminUser::class.'",username|string|max:100|min:3',
            'avatar'   => 'nullable|string|max:255',
            'password' => $this->rulePassword(),
            'email'    => 'required|string|email|max:255|unique:"'.AdminUser::class.'",email',
        ], [
            'username.regex' => gp247_language_render('admin.user.username_validate'),
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        $dataCreate = [
            'name'     => $data['name'],
            'username' => strtolower($data['username']),
            'avatar'   => $data['avatar'],
            'email'    => strtolower($data['email']),
            'password' => bcrypt($data['password']),
        ];
        $dataCreate = gp247_clean($dataCreate, [], true);
        $user = AdminUser::createUser($dataCreate);

        $roles = $data['roles'] ?? [];
        $permission = $data['permission'] ?? [];

        //Process role special
        if (in_array(1, $roles)) {
            // If group admin
            $roles = [1];
            $permission = [];
        } elseif (in_array(2, $roles)) {
            // If group onlyview
            $roles = [2];
            $permission = [];
        }
        //End process role special

        //Insert roles
        if ($roles) {
            $user->roles()->attach($roles);
        }
        //Insert permission
        if ($permission) {
            $user->permissions()->attach($permission);
        }

        return redirect()->route('admin_user.index')->with('success', gp247_language_render('action.create_success'));
    }

    /**
     * Form edit
     */
    public function edit($id)
    {
        $user = AdminUser::find($id);
        if ($user === null) {
            return redirect(gp247_route_admin('admin_role.index'))->with('error', gp247_language_render('display.data_not_found'));
            return gp247_language_render('display.data_not_found_detail');
        }
        if ($user->id == admin()->user()->id) {
            return redirect()->route('admin.setting');
        }
        $data = [
            'title'             => gp247_language_render('action.edit'),
            'subTitle'          => '',
            'title_description' => '',
            'user'              => $user,
            'roles'             => $this->roles,
            'permissions'       => $this->permissions,
            'url_action'        => gp247_route_admin('admin_user.post_edit', ['id' => $user['id']]),
            'isAllStore'        => ($user->isAdministrator() || $user->isViewAll()) ? 1: 0,

        ];
        return view('gp247-core::auth.user')
            ->with($data);
    }

    /**
     * update status
     */
    public function postEdit($id)
    {
        $user = AdminUser::find($id);
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'name'     => 'required|string|max:100',
            'username' => 'required|regex:/(^([0-9A-Za-z@\._]+)$)/|unique:"'.AdminUser::class.'",username,' . $user->id . '|string|max:100|min:3',
            'avatar'   => 'nullable|string|max:255',
            'password' => $this->rulePasswordNullable(),
            'email'    => 'required|string|email|max:255|unique:"'.AdminUser::class.'",email,' . $user->id . ',id',
        ], [
            'username.regex' => gp247_language_render('admin.user.username_validate'),
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }
        //Edit
        $dataUpdate = [
            'name' => $data['name'],
            'username' => strtolower($data['username']),
            'avatar' => $data['avatar'],
            'email' => strtolower($data['email']),
        ];

        if ($id != admin()->user()->id) {
            //Ony change status if not current user
            $dataUpdate['status'] = empty($data['status']) ? 0 : 1;
        }
        
        if ($data['password']) {
            $dataUpdate['password'] = bcrypt($data['password']);
        }
        $dataUpdate = gp247_clean($dataUpdate, [], true);
        AdminUser::updateInfo($dataUpdate, $id);

        if (!in_array($user->id, GP247_GUARD_ADMIN)) {
            $roles = $data['roles'] ?? [];
            $permission = $data['permission'] ?? [];
            $user->roles()->detach();
            $user->permissions()->detach();
            //Insert roles
            if ($roles) {
                $user->roles()->attach($roles);
            }
            //Insert permission
            if ($permission) {
                $user->permissions()->attach($permission);
            }
        }

        return redirect()->route('admin_user.index')->with('success', gp247_language_render('action.edit_success'));
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
            $arrID = array_diff($arrID, GP247_GUARD_ADMIN);
            AdminUser::destroy($arrID);
            return response()->json(['error' => 1, 'msg' => '']);
        }
    }
}
