<?php

use GP247\Core\AdminShell\Http\Livewire\UserManager;

// Cutover PA-1 (modification 20260629T022055): legacy Auth\UsersController is replaced
// by the UserManager panel (TailAdmin/Livewire). Old admin URL + route names kept
// (canonical); create/edit/delete run inside the component over livewire/update, so
// legacy POST routes are removed (US-AUI-010/011).
Route::group(['prefix' => 'user'], function () {
    Route::get('/', gp247_namespace(UserManager::class))->name('admin_user.index');
    Route::get('create', gp247_namespace(UserManager::class))->name('admin_user.create');
    Route::get('edit/{id}', gp247_namespace(UserManager::class))->name('admin_user.edit');
});
