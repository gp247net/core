<?php

use GP247\Core\AdminShell\Http\Livewire\RoleManager;

// Cutover PA-1 (modification 20260629T022055): legacy Auth\RoleController is replaced
// by the RoleManager panel (TailAdmin/Livewire). Old admin URL + route names kept
// (canonical); create/edit/delete run inside the component over livewire/update, so
// legacy POST routes are removed (US-AUI-010/011).
Route::group(['prefix' => 'role'], function () {
    Route::get('/', gp247_namespace(RoleManager::class))->name('admin_role.index');
    Route::get('create', gp247_namespace(RoleManager::class))->name('admin_role.create');
    Route::get('edit/{id}', gp247_namespace(RoleManager::class))->name('admin_role.edit');
});
