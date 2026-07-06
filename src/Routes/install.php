<?php

use GP247\Core\AdminShell\Http\Livewire\InstallerWizard;
use Illuminate\Support\Facades\Route;

/**
 * GP247 web installer route.
 *
 * Registered in CoreServiceProvider::boot() BEFORE the gp247-installed.txt
 * guard so it is reachable even before the platform is installed.
 * InstallerWizard::mount() issues a redirect when the flag already exists.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-001
 */
Route::get('/install', InstallerWizard::class)->name('gp247.install');
