<?php

use GP247\Core\SecurityHeaders\Livewire\SecurityHeadersSettingsForm;
use Illuminate\Support\Facades\Route;

// "Security headers" screen (US-security-headers-settings, ADR security-headers_config-hsts-and-kill-switch).
// Administrator only: the path is in PermissionMiddleware::administratorOnly() and the
// component gates mount / save itself.
Route::get('security_headers', gp247_namespace(SecurityHeadersSettingsForm::class))->name('admin.security_headers');
