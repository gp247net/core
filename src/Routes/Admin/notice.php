<?php

use GP247\Core\AdminShell\Http\Livewire\NoticeList;
use GP247\Core\Controllers\AdminNoticeController;

// Cutover PA-1 (modification 20260629T022055): the legacy notice list screen is replaced
// by NoticeList (TailAdmin/Livewire). The old admin URL + route name are kept (canonical)
// and now render the modern component; bulk delete runs over livewire/update, so the
// legacy POST route is removed. mark_read / url stay as plain GET endpoints because the
// header dropdown links to them directly (US-AUI-010/011).
$noticeController = gp247_namespace(AdminNoticeController::class);
Route::group(['prefix' => 'notice'], function () use ($noticeController) {
    Route::get('/', gp247_namespace(NoticeList::class))->name('admin_notice.index');
    Route::get('mark_read', $noticeController.'@markRead')->name('admin_notice.mark_read');
    Route::get('url/{type}/{typeId}', $noticeController.'@url')->name('admin_notice.url');
});
