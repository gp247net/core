<?php

use GP247\Core\AdminShell\Http\Livewire\LoginFailureList;
use Illuminate\Support\Facades\Route;

// "Login failures" screen (US-admin-shell-login-failure-list, ADR admin-shell_login-failure-log).
// Administrator only: the path is in PermissionMiddleware::administratorOnly() and the
// component gates mount / save itself. Read-only list; rows leave by the keep period.
Route::get('login_failures', gp247_namespace(LoginFailureList::class))->name('admin.login_failures');
