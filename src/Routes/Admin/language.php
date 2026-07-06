<?php

use GP247\Core\AdminShell\Http\Livewire\LanguageManager;

// Cutover PA-1 (modification 20260629T022055): legacy AdminLanguageController is
// replaced by the LanguageManager panel (TailAdmin/Livewire). Old admin URL + route
// names kept (canonical); create/edit/delete run inside the component over
// livewire/update, so legacy POST routes are removed (US-AUI-010/011).
Route::group(['prefix' => 'language'], function () {
    Route::get('/', gp247_namespace(LanguageManager::class))->name('admin_language.index');
    Route::get('edit/{id}', gp247_namespace(LanguageManager::class))->name('admin_language.edit');
});
