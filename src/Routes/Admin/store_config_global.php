<?php

use GP247\Core\AdminShell\Http\Livewire\GlobalConfigForm;

// Cutover PA-1 (modification 20260629T022055): legacy AdminConfigGlobalController is
// replaced by GlobalConfigForm (TailAdmin/Livewire). Old admin URL + route name kept
// (canonical); update runs inside the component over livewire/update, so the legacy
// POST route is removed (US-AUI-010/011).
Route::group(['prefix' => 'config'], function () {
    Route::get('/webhook', gp247_namespace(GlobalConfigForm::class))->name('admin_config_global.webhook');
});
