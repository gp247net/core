<?php

use GP247\Core\AdminShell\Http\Livewire\ApiConnectionManager;

// Cutover PA-1 (modification 20260629T022055): the legacy AdminApiConnectionController
// screen is replaced by the TailAdmin/Livewire ApiConnectionManager panel. The old
// admin URL + route name are kept (canonical) and now render the modern component;
// create/edit/delete/generate now run inside the component over livewire/update, so
// the legacy POST/GET action routes are removed (US-AUI-010/011).
Route::group(['prefix' => 'api_connection'], function () {
    Route::get('/', ApiConnectionManager::class)->name('admin_api_connection.index');
});
