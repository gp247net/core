<?php

use GP247\Core\Api\Support\ApiAbilities;
use GP247\Core\Api\V2\AdminAuthController;
use Illuminate\Support\Facades\Route;

// REST API v2 — admin authentication (US-CMP-api-v2-contract). Logout is POST only.
Route::group([
    'prefix' => GP247_API_V2_CORE_PREFIX,
], function () {
    $authController = gp247_namespace(AdminAuthController::class);
    Route::post('login', $authController.'@login');

    Route::group([
        'middleware' => ['auth:admin-api', ApiAbilities::admin(), 'api.admin.active'],
    ], function () use ($authController) {
        Route::post('logout', $authController.'@logout');
        Route::get('info', $authController.'@info');
    });
});
