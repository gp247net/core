<?php

use GP247\Core\PersonalData\Livewire\PrivacyRequestList;
use Illuminate\Support\Facades\Route;

// "Personal data requests" screen (US-personal-data-requests-screen, ADR personal-data-requests_access-and-settings).
// Own permission, given to no role at install: Administrators only until the owner assigns it.
Route::get('personal_data_requests', gp247_namespace(PrivacyRequestList::class))->name('admin.personal_data_requests');
