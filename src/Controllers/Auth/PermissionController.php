<?php
namespace GP247\Core\Controllers\Auth;

use GP247\Core\Models\AdminPermission;
use GP247\Core\Controllers\RootAdminController;
use Illuminate\Support\Str;
use Validator;

class PermissionController extends RootAdminController
{
    public $routeAdmin;

    public function __construct()
    {
        parent::__construct();
        
        $routes = app()->routes->getRoutes();

        foreach ($routes as $route) {
            if (Str::startsWith($route->uri(), GP247_ADMIN_PREFIX)) {
                $prefix = ltrim($route->getPrefix(), '/');
                $routeAdmin[$prefix] = [
                    'uri'    => 'ANY::' . $prefix . '/*',
                    'name'   => $prefix . '/*',
                    'method' => 'ANY',
                ];
                foreach ($route->methods as $key => $method) {
                    if ($method != 'HEAD' && !collect($this->without())->first(function ($exp) use ($route) {
                        return Str::startsWith($route->uri, $exp);
                    })) {
                        $routeAdmin[] = [
                            'uri'    => $method . '::' . $route->uri,
                            'name'   => $route->uri,
                            'method' => $method,
                        ];
                    }
                }
            }
        }

        $this->routeAdmin = $routeAdmin;
    }

    public function index()
    {
        $data = [
            'title' => gp247_language_render('admin.permission.list'),
            'subTitle' => '',
            'urlDeleteItem' => gp247_route_admin('admin_permission.delete'),
            'removeList' => 1, // 1 - Enable function delete list item
            'buttonRefresh' => 1, // 1 - Enable button refresh
            'buttonSort' => 1, // 1 - Enable button sort
        ];

        $listTh = [
            'id' => 'ID',
            'name' => gp247_language_render('admin.permission.name'),
            'slug' => gp247_language_render('admin.permission.slug'),
            'http_path' => gp247_language_render('admin.permission.http_path'),
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
        $obj = new AdminPermission;
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
            $permissions = '';
            if ($row['http_uri']) {
                $methods = array_map(function ($value) {
                    $route = explode('::', $value);
                    $methodStyle = '';
                    if ($route[0] == 'ANY') {
                        $methodStyle = '<span class="badge badge-info">' . $route[0] . '</span>';
                    } elseif ($route[0] == 'POST') {
                        $methodStyle = '<span class="badge badge-warning">' . $route[0] . '</span>';
                    } else {
                        $methodStyle = '<span class="badge badge-primary">' . $route[0] . '</span>';
                    }
                    return $methodStyle . ' <code>' . $route[1] . '</code>';
                }, explode(',', $row['http_uri']));
                $permissions = implode('<br>', $methods);
            }
            $arrAction = [
                '<a href="' . gp247_route_admin('admin_permission.edit', ['id' => $row['id'] ? $row['id'] : 'not-found-id']) . '"  class="dropdown-item"><i class="fa fa-edit"></i> '.gp247_language_render('action.edit').'</a>',
                '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>',
                ];
                $action = $this->procesListAction($arrAction);

            $dataTr[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'permission' => $permissions,
                'updated_at' => $row['updated_at'],
                'action' => $action,
            ];
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
        $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);

        //menuRight
        $data['menuRight'][] = '<a href="' . gp247_route_admin('admin_permission.create') . '" class="btn btn-sm  btn-success  btn-flat" title="New" id="button_create_new">
                           <i class="fa fa-plus" title="'.gp247_language_render('action.add').'"></i>
                           </a>';
        //=menuRight

        //menuSort
        $optionSort = '';
        foreach ($arrSort as $kSort => $vSort) {
            $optionSort .= '<option  ' . (($sort == $kSort) ? "selected" : "") . ' value="' . $kSort . '">' . $vSort . '</option>';
        }
        $data['optionSort'] = $optionSort;
        //=menuSort

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
            'title' => gp247_language_render('admin.permission.add_new_title'),
            'subTitle' => '',
            'title_description' => gp247_language_render('admin.permission.add_new_des'),
            'permission' => [],
            'routeAdmin' => $this->routeAdmin,
            'url_action' => gp247_route_admin('admin_permission.post_create'),

        ];

        return view('gp247-core::auth.permission')
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
            'name' => 'required|string|max:50|unique:"'.AdminPermission::class.'",name',
            'slug' => 'required|regex:/(^([0-9A-Za-z\._\-]+)$)/|unique:"'.AdminPermission::class.'",slug|string|max:50|min:3',
        ], [
            'slug.regex' => gp247_language_render('admin.permission.slug_validate'),
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $dataCreate = [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'http_uri' => implode(',', ($data['http_uri'] ?? [])),
        ];
        $dataCreate = gp247_clean($dataCreate, [], true);
        $permission = AdminPermission::createPermission($dataCreate);

        return redirect()->route('admin_permission.index')->with('success', gp247_language_render('action.create_success'));
    }

    /**
     * Form edit
     */
    public function edit($id)
    {
        $permission = AdminPermission::find($id);
        if ($permission === null) {
            return redirect(gp247_route_admin('admin_permission.index'))->with('error', gp247_language_render('display.data_not_found'));
        }
        $data = [
            'title' => gp247_language_render('action.edit'),
            'subTitle' => '',
            'title_description' => '',
            'permission' => $permission,
            'routeAdmin' => $this->routeAdmin,
            'url_action' => gp247_route_admin('admin_permission.post_edit', ['id' => $permission['id']]),
        ];
        return view('gp247-core::auth.permission')
            ->with($data);
    }

    /**
     * update status
     */
    public function postEdit($id)
    {
        $permission = AdminPermission::find($id);
        $data = request()->all();
        $dataOrigin = request()->all();
        $validator = Validator::make($dataOrigin, [
            'name' => 'required|string|max:50|unique:"'.AdminPermission::class.'",name,' . $permission->id . '',
            'slug' => 'required|regex:/(^([0-9A-Za-z\._\-]+)$)/|unique:"'.AdminPermission::class.'",slug,' . $permission->id . '|string|max:50|min:3',
        ], [
            'slug.regex' => gp247_language_render('admin.permission.slug_validate'),
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
            'http_uri' => implode(',', ($data['http_uri'] ?? [])),
        ];
        $dataUpdate = gp247_clean($dataUpdate, [], true);
        $permission->update($dataUpdate);
        return redirect()->route('admin_permission.index')->with('success', gp247_language_render('action.edit_success'));
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
            AdminPermission::destroy($arrID);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
        }
    }

    public function without()
    {
        $prefix = GP247_ADMIN_PREFIX?GP247_ADMIN_PREFIX.'/':'';
        return [
            $prefix . 'login',
            $prefix . 'logout',
            $prefix . 'forgot',
            $prefix . 'deny',
            $prefix . 'locale',
            $prefix . 'uploads',
        ];
    }
}
