<?php

use GP247\Core\AdminShell\Http\Livewire\MenuManager;

// Cutover PA-1 (modification 20260629T022055): legacy AdminMenuController is replaced
// by the MenuManager panel (TailAdmin/Livewire). Old admin URL + route names kept
// (canonical); create/edit/delete/sort run inside the component over livewire/update,
// so legacy POST routes are removed (US-AUI-010/011).
Route::group(['prefix' => 'menu'], function () {
    Route::get('/', MenuManager::class)->name('admin_menu.index');
    Route::get('edit/{id}', MenuManager::class)->name('admin_menu.edit');
});
