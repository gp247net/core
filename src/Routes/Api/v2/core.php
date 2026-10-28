<?php

use GP247\Core\Api\Support\ApiAbilities;
use GP247\Core\Api\V2\AdminAuthController;
use Illuminate\Support\Facades\Route;

// REST API v2 — admin authentication and API sessions (US-CMP-api-v2-contract,
// US-CMP-api-refresh-token-rotation, US-CMP-api-session-management). Logout is POST only.
Route::group([
    'prefix' => GP247_API_V2_CORE_PREFIX,
], function () {
    $authController = gp247_namespace(AdminAuthController::class);
    Route::post('login', $authController.'@login');
    Route::post('login/challenge', $authController.'@challenge');
    Route::post('refresh', $authController.'@refresh');

    Route::group([
        'middleware' => ['auth:admin-api', ApiAbilities::admin(), 'api.admin.active'],
    ], function () use ($authController) {
        Route::post('logout', $authController.'@logout');
        Route::get('info', $authController.'@info');
        Route::get('sessions', $authController.'@sessions');
        Route::delete('sessions/{id}', $authController.'@revokeSession');
    });
});
