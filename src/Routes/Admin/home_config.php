<?php

use GP247\Core\AdminShell\Http\Livewire\HomeLayoutManager;

// Two-panel manager (modification 20260630): form + list on one page via
// HomeLayoutManager (ResourcePanel). Route names kept for back-compat;
// create route maps to index (form is always visible on the left panel).
Route::group(['prefix' => 'admin_home_layout'], function () {
    Route::get('/', gp247_namespace(HomeLayoutManager::class))->name('admin_home_layout.index');
    Route::get('create', gp247_namespace(HomeLayoutManager::class))->name('admin_home_layout.create');
    Route::get('edit/{id}', gp247_namespace(HomeLayoutManager::class))->name('admin_home_layout.edit');
});
