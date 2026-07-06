<?php

use GP247\Core\AdminShell\Http\Livewire\ServerInfo;
use Illuminate\Support\Facades\Route;

// Cutover PA-1 (modification 20260629T022055): legacy AdminServerInfoController is
// replaced by the ServerInfo component (TailAdmin/Livewire). Old admin URL + route
// name kept (canonical); read-only screen, no POST routes (US-AUI-010).
Route::get('server_info', ServerInfo::class)->name('admin.server_info');
