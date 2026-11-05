<?php

use GP247\Core\Backup\Http\DownloadController;
use GP247\Core\Backup\Http\TickController;
use GP247\Core\Backup\Livewire\BackupDashboard;
use GP247\Core\Backup\Livewire\BackupRestore;
use GP247\Core\Backup\Livewire\BackupSettings;
use Illuminate\Support\Facades\Route;

// Backup & restore area (logical design backup-admin §2, ADR backup-admin_step-up-and-signed-download).
// Admin web guard only — no API route, so API / MCP tokens can never create, download or restore.
// Permissions (RBAC layer 2): backup.create = ANY archives + POST tick; backup.download = GET archives +
// ANY download/*; backup.restore = GET archives + ANY restore*. Settings are Administrator only
// (PermissionMiddleware::administratorOnly()). Every component / controller checks its right again.
Route::group(['prefix' => 'backup'], function () {
    Route::get('archives', gp247_namespace(BackupDashboard::class))->name('admin.backup.archives');
    Route::post('tick', gp247_namespace(TickController::class))->name(TickController::ROUTE_CREATE);
    Route::get('download/{token}', gp247_namespace(DownloadController::class))
        ->where('token', '[0-9a-f]{64}')
        ->name('admin.backup.download');
    Route::get('restore', gp247_namespace(BackupRestore::class))->name('admin.backup.restore');
    Route::post('restore/tick', gp247_namespace(TickController::class))->name(TickController::ROUTE_RESTORE);
    Route::get('settings', gp247_namespace(BackupSettings::class))->name('admin.backup.settings');
});
