<?php

use GP247\Core\PersonalData\Livewire\RetentionScreen;
use Illuminate\Support\Facades\Route;

// "Data retention" screen (US-personal-data-retention-policies, ADR personal-data-retention_retention-registry).
// Administrators only: the path is in PermissionMiddleware::administratorOnly().
Route::get('personal_data_retention', gp247_namespace(RetentionScreen::class))->name('admin.personal_data_retention');
