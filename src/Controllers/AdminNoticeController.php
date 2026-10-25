<?php
namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminNotice;
class AdminNoticeController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
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
}
