<?php
namespace GP247\Core\Controllers;

use GP247\Core\Models\AdminLog;
use GP247\Core\Models\AdminUser;
use GP247\Core\Controllers\RootAdminController;

class AdminLogController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }
    
    public function index()
    {
        $data = [
            'title' => gp247_language_render('admin.log.list'),
            'subTitle' => '',
            'urlDeleteItem' => gp247_route_admin('admin_log.delete'),
            'removeList' => 1, // 1 - Enable function delete list item
            'buttonRefresh' => 1, // 1 - Enable button refresh
        ];

        $listTh = [
            'user_id' => 'UID',
            'user_name' => gp247_language_render('admin.log.user'),
            'method' => gp247_language_render('admin.log.method'),
            'path' => gp247_language_render('admin.log.path'),
            'ip' => gp247_language_render('admin.log.ip'),
            'user_agent' => gp247_language_render('admin.log.user_agent'),
            'input' => gp247_language_render('admin.log.input'),
            'created_at' => gp247_language_render('admin.log.created_at'),
            'action' => gp247_language_render('action.title'),
        ];

        $keyword     = gp247_clean(request('keyword') ?? '');
        $sort = gp247_clean(request('sort') ?? 'id_desc');
        $arrSort = [
            'id__desc' => gp247_language_render('filter_sort.id_desc'),
            'id__asc' => gp247_language_render('filter_sort.id_asc'),
            'user_id__desc' => gp247_language_render('filter_sort.value_desc', ['value' => 'UID']),
            'user_id__asc' => gp247_language_render('filter_sort.value_asc', ['value' => 'UID']),
            'path__desc' => gp247_language_render('filter_sort.alpha_desc', ['alpha' => 'Path']),
            'path__asc' => gp247_language_render('filter_sort.alpha_asc', ['alpha' => 'Path']),
            'user_agent__desc' => gp247_language_render('filter_sort.alpha_desc', ['alpha' => 'User agent']),
            'user_agent__asc' => gp247_language_render('filter_sort.alpha_asc', ['alpha' => 'User agent']),
            'method__desc' => gp247_language_render('filter_sort.alpha_desc', ['alpha' => 'Method']),
            'method__asc' => gp247_language_render('filter_sort.alpha_asc', ['alpha' => 'Method']),
            'ip__desc' => gp247_language_render('filter_sort.alpha_desc', ['alpha' => 'Ip']),
            'ip__asc' => gp247_language_render('filter_sort.alpha_asc', ['alpha' => 'Ip']),

        ];
        $obj = new AdminLog;
        if ($keyword) {
            $obj = $obj->where(function ($query) use($keyword){
                $query->where('ip', $keyword)
                      ->orWhere('path', $keyword);
            });
        }
        if ($sort && array_key_exists($sort, $arrSort)) {
            $field = explode('__', $sort)[0];
            $sort_field = explode('__', $sort)[1];
            if ($field == 'id') {
                $field = 'created_at';
            }
            $obj = $obj->orderBy($field, $sort_field);
        } else {
            $obj = $obj->orderBy('created_at', 'desc');
        }
        $dataTmp = $obj->paginate(20);

        $dataTr = [];
        foreach ($dataTmp as $key => $row) {
            $arrAction = [
            '<a href="#" onclick="deleteItem(\'' . $row['id'] . '\');"  title="' . gp247_language_render('action.delete') . '" class="dropdown-item"><i class="fas fa-trash-alt"></i> '.gp247_language_render('action.remove').'</a>',
            ];
            $action = $this->procesListAction($arrAction);


            $dataTr[$row['id']] = [
                'user_id' => $row['user_id'],
                'user_name' => ($user = AdminUser::find($row['user_id'])) ? $user->name : 'N/A',
                'method' => '<span class="badge bg-' . (AdminLog::$methodColors[$row['method']] ?? '') . '">' . $row['method'] . '</span>',
                'path' => '<code>' . $row['path'] . '</code>',
                'ip' => $row['ip'],
                'user_agent' => $row['user_agent'],
                'input' => htmlspecialchars($row['input']),
                'created_at' => $row['created_at'],
                'action' => $action,
            ];
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
        $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);

        //menuSearch
        $optionSort = '';
        foreach ($arrSort as $kSort => $vSort) {
            $optionSort .= '<option  ' . (($sort == $kSort) ? "selected" : "") . ' value="' . $kSort . '">' . $vSort . '</option>';
        }
        //=menuSort

        //topMenuRight
        $data['topMenuRight'][] ='
                <form action="' . gp247_route_admin('admin_log.index') . '" id="button_search">
                <div class="input-group input-group float-left">
                    <select class="form-control form-control-sm rounded-0 select2" name="sort" id="sort">
                    '.$optionSort.'
                    </select> &nbsp;
                    <input type="text" name="keyword" class="form-control form-control-sm rounded-0 float-right" placeholder="Search: IP,Path,Name" value="' . $keyword . '">
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search"></i></button>
                    </div>
                </div>
                </form>';
        //=topMenuRight

        return view('gp247-core::screen.list')
            ->with($data);
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
            AdminLog::destroy($arrID);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
        }
    }
}
