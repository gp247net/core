<?php

use GP247\Core\AdminShell\Http\Livewire\CacheConfigForm;

// Cutover PA-1 (modification 20260629T022055): legacy AdminCacheConfigController is
// replaced by CacheConfigForm (TailAdmin/Livewire). Old admin URL + route name kept
// (canonical); clear-cache runs inside the component over livewire/update, so the
// legacy POST route is removed (US-AUI-010/011).
Route::group(['prefix' => 'cache_config'], function () {
    Route::get('/', gp247_namespace(CacheConfigForm::class))->name('admin_cache_config.index');
});
