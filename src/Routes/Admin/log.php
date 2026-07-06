<?php

use GP247\Core\AdminShell\Http\Livewire\AdminLogList;

// Cutover PA-1 (modification 20260629T022055): legacy AdminLogController is replaced by
// AdminLogList (TailAdmin/Livewire). Old admin URL + route name kept (canonical);
// delete runs inside the component over livewire/update, so the legacy POST route is
// removed (US-AUI-010/011).
Route::group(['prefix' => 'log'], function () {
    Route::get('/', gp247_namespace(AdminLogList::class))->name('admin_log.index');
});
