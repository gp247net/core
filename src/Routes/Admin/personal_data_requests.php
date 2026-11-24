<?php

use GP247\Core\PersonalData\Http\ExportDownloadController;
use GP247\Core\PersonalData\Livewire\PrivacyRequestList;
use Illuminate\Support\Facades\Route;

// "Personal data requests" screen (US-personal-data-requests-screen, ADR personal-data-requests_access-and-settings).
// Own permission, given to no role at install: Administrators only until the owner assigns it.
Route::get('personal_data_requests', gp247_namespace(PrivacyRequestList::class))->name('admin.personal_data_requests');
// A customer's data package, downloaded once by the administrator the link was given to (US-personal-data-requests-admin-create).
Route::get('personal_data_requests/download/{export}/{token}', gp247_namespace(ExportDownloadController::class))
    ->where(['export' => '[0-9A-Za-z]{26}', 'token' => '[0-9A-Za-z]{48}'])
    ->name('admin.personal_data_requests.download');
