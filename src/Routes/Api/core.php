<?php

use GP247\Core\Api\Controllers\AdminAuthController;
use GP247\Core\Api\Controllers\AdminController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => GP247_API_CORE_PREFIX,
], function (){

    $listAbility = [
        config('gp247-config.api.auth.api_scope_admin'),
        config('gp247-config.api.auth.api_scope_admin_supper')
    ];

    $authController = gp247_namespace(AdminAuthController::class);
    //Login
    Route::post('login', $authController.'@login');

    Route::group([
        'middleware' => [
            'auth:admin-api',
            'ability:'.implode(',', $listAbility)
        ]
    ], function () use($authController){
        //Logout
        Route::get('logout', $authController.'@logout');

        //Admin info
        $adminController = gp247_namespace(AdminController::class);
        Route::get('info', $adminController.'@getInfo');
    });


});
