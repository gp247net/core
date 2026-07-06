<?php

use GP247\Core\AdminShell\Http\Livewire\PasswordPolicyForm;

// Cutover PA-1 (modification 20260629T022055): legacy AdminPasswordPolicyController is
// replaced by PasswordPolicyForm (TailAdmin/Livewire). Old admin URL + route name kept
// (canonical); update runs inside the component over livewire/update, so the legacy
// POST route is removed (US-AUI-010/011).
Route::group(['prefix' => 'password_policy'], function () {
    Route::get('/', PasswordPolicyForm::class)->name('admin_password_policy.index');
});
