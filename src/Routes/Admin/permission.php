<?php

use GP247\Core\AdminShell\Http\Livewire\PermissionManager;

// Cutover PA-1 (modification 20260629T022055): legacy Auth\PermissionController is
// replaced by the PermissionManager panel (TailAdmin/Livewire). Old admin URL + route
// names kept (canonical); create/edit/delete run inside the component over
// livewire/update, so legacy POST routes are removed (US-AUI-010/011).
Route::group(['prefix' => 'permission'], function () {
    Route::get('/', gp247_namespace(PermissionManager::class))->name('admin_permission.index');
    Route::get('create', gp247_namespace(PermissionManager::class))->name('admin_permission.create');
    Route::get('edit/{id}', gp247_namespace(PermissionManager::class))->name('admin_permission.edit');
});
