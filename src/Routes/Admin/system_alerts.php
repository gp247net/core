<?php

use GP247\Core\AdminShell\Http\Livewire\SystemAlerts;
use Illuminate\Support\Facades\Route;

// "System alerts" page (US-admin-shell-system-alerts-page, ADR admin-shell_system-alerts-page).
// Administrator only: the path is in PermissionMiddleware::administratorOnly() and the
// component gates mount/recheck itself. Read-only screen; "Check again" runs over livewire/update.
Route::get('system_alerts', gp247_namespace(SystemAlerts::class))->name('admin.system_alerts');
