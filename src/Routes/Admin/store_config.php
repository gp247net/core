<?php

use GP247\Core\AdminShell\Http\Livewire\SettingsHub;

// Cutover PA-1 (modification 20260629T022055): legacy AdminStoreConfigController is
// replaced by the SettingsHub (TailAdmin/Livewire). Old admin URL + route name kept
// (canonical); update/add/delete run over livewire/update, so legacy POST routes are
// removed (US-AUI-010/011).
Route::group(['prefix' => 'store_config'], function () {
    Route::get('/', gp247_namespace(SettingsHub::class))->name('admin_config.index');
});
