<?php

use GP247\Core\AdminShell\Http\Livewire\LanguageStringManager;
use Illuminate\Support\Facades\Route;

// LanguageManager — cutover (PA-1): all CRUD (inline edit, add, delete) is
// handled by LanguageStringManager via Livewire. Legacy POST endpoints
// (update, add) are removed — no external references outside old controller.
// Route name admin_language_manager.index kept canonical.
Route::group(['prefix' => 'language_manager'], function () {
    Route::get('/', gp247_namespace(LanguageStringManager::class))->name('admin_language_manager.index');
});
