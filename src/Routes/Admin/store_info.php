<?php

use GP247\Core\AdminShell\Http\Livewire\WebsiteInfo;

// Cutover PA-1 (modification 20260629T022055): legacy AdminStoreInfoController is
// replaced by WebsiteInfo (TailAdmin/Livewire). Old admin URL + route name kept
// (canonical); update runs inside the component over livewire/update, so the legacy
// POST route is removed (US-AUI-010/011).
Route::group(['prefix' => 'store_info'], function () {
    Route::get('/', gp247_namespace(WebsiteInfo::class))->name('admin_store.index');
});
