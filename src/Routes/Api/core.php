<?php

use GP247\Core\Api\Controllers\AdminAuthController;
use GP247\Core\Api\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => GP247_API_CORE_PREFIX,
], function (){

    $authController = gp247_namespace(AdminAuthController::class);
    //Login
    Route::post('login', $authController.'@login');

    Route::group([
        'middleware' => [
            'auth:admin-api',
            \GP247\Core\Api\Support\ApiAbilities::admin(),
            'api.admin.active',
        ]
    ], function () use($authController){
        //Logout — POST is the supported method; GET is kept for v1 clients (deprecated)
        Route::post('logout', $authController.'@logout');
        Route::get('logout', $authController.'@logout');

        //Admin info
        $adminController = gp247_namespace(AdminController::class);
        Route::get('info', $adminController.'@getInfo');
    });


});
