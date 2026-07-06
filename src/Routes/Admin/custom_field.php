<?php

use GP247\Core\AdminShell\Http\Livewire\CustomFieldForm;
use GP247\Core\AdminShell\Http\Livewire\CustomFieldList;

// Cutover PA-1 (modification 20260629T022055): legacy AdminCustomFieldController is
// replaced by the TailAdmin/Livewire CustomFieldList + CustomFieldForm. Old admin
// URL + route names are kept (canonical) and now render the modern components;
// create/edit/delete run inside the components over livewire/update, so the legacy
// POST action routes are removed (US-AUI-010/011).
Route::group(['prefix' => 'custom_field'], function () {
    Route::get('/', CustomFieldList::class)->name('admin_custom_field.index');
    Route::get('create', CustomFieldForm::class)->name('admin_custom_field.create');
    Route::get('edit/{id}', CustomFieldForm::class)->name('admin_custom_field.edit');
});
