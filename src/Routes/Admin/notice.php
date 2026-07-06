<?php

use GP247\Core\AdminShell\Http\Livewire\NoticeList;

// Cutover PA-1 (modification 20260629T022055): the legacy notice list screen is replaced
// by NoticeList (TailAdmin/Livewire). The old admin URL + route name are kept (canonical)
// and now render the modern component; bulk delete runs over livewire/update, so the
// legacy POST route is removed. mark_read / url stay as plain GET endpoints because the
// header dropdown links to them directly (US-AUI-010/011).
if (file_exists(app_path('GP247/Core/Controllers/AdminNoticeController.php'))) {
    $nameSpaceAdmin = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdmin = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'notice'], function () use ($nameSpaceAdmin) {
    Route::get('/', NoticeList::class)->name('admin_notice.index');
    Route::get('mark_read', $nameSpaceAdmin.'\AdminNoticeController@markRead')->name('admin_notice.mark_read');
    Route::get('url/{type}/{typeId}', $nameSpaceAdmin.'\AdminNoticeController@url')->name('admin_notice.url');
});
