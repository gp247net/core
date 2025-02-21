<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use Validator;
use GP247\Core\Models\AdminNotice;
class AdminNoticeController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $data = [
            'title'         => gp247_language_render('admin_notice.title'),
            'subTitle'      => '',
            'urlDeleteItem' => gp247_route_admin('admin_notice.delete'),
            'removeList'    => 1, // 1 - Enable function delete list item
            'buttonRefresh' => 1, // 1 - Enable button refresh
        ];

        $listTh = [
            'type'    => gp247_language_render('admin_notice.type'),
            'type_id' => gp247_language_render('admin_notice.type_id'),
            'content' => gp247_language_render('admin_notice.content'),
            'admin_created' => gp247_language_render('admin_notice.admin_created'),
            'date'    => gp247_language_render('admin_notice.created_at'),
        ];
        $dataTmp = (new AdminNotice)->getNoticeListAdmin();

        $dataTr = [];
        foreach ($dataTmp as $key => $row) {
            $statusRead = (($row->status) ? 'read':'unread');
            $dataMap = [
                'type' => '<span class="notice-'.$statusRead.'" >'.$row->type.'</span>',
                'type_id' => '<span class="notice-'.$statusRead.'" >'.$row->type_id.'</span>',
                'content' => '<span class="notice-'.$statusRead.'" >'.gp247_content_render($row->content).'</span>',
                'admin_created' => '<span class="notice-'.$statusRead.'" >'.($row->admin->name ?? $row->admin_created).'</span>',
                'date' => $row->created_at,
            ];
            $dataTr[$row['id']] = $dataMap;
        }

        $data['listTh'] = $listTh;
        $data['dataTr'] = $dataTr;
        $data['pagination'] = $dataTmp->appends(request()->except(['_token', '_pjax']))->links('gp247-core::component.pagination');
        $data['resultItems'] = gp247_language_render('admin.result_item', ['item_from' => $dataTmp->firstItem(), 'item_to' => $dataTmp->lastItem(), 'total' =>  $dataTmp->total()]);

        return view('gp247-core::screen.list')
            ->with($data);
    }

    /**
     * [markRead description]
     *
     * @return  [type]  [return description]
     */
    public function markRead() {

        (new AdminNotice)->where('admin_id', admin()->user()->id)
        ->update(['status' => 1]);
        return redirect()->back();
    }

    public function url(string $type, string $typeId) {
        (new AdminNotice)
        ->where('admin_id', admin()->user()->id)
        ->where('type', $type)
        ->where('type_id', $typeId)
        ->update(['status' => 1]);

        return redirect(gp247_route_admin('admin_notice.index'));
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
            AdminNotice::destroy($arrID);
            return response()->json(['error' => 0, 'msg' => gp247_language_render('action.delete_success')]);
        }
    }
}
