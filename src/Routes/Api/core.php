<?php
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => GP247_API_CORE_PREFIX,
], function (){
    
    $listAbility = [
        config('gp247-config.api.auth.api_scope_admin'),
        config('gp247-config.api.auth.api_scope_admin_supper')
    ];

    if (file_exists(app_path('GP247/Core/Api/Controllers/AdminAuthController.php'))) {
        $nameSpaceAdminAuth = 'App\GP247\Core\Api\Controllers';
    } else {
        $nameSpaceAdminAuth = 'GP247\Core\Api\Controllers';
    }
    //Login
    Route::post('login', $nameSpaceAdminAuth.'\AdminAuthController@login');

    Route::group([
        'middleware' => [
            'auth:admin-api', 
            'ability:'.implode(',', $listAbility)
        ]
    ], function () use($nameSpaceAdminAuth){
        //Logout
        Route::get('logout', $nameSpaceAdminAuth.'\AdminAuthController@logout');
        
        //Admin info
        if (file_exists(app_path('GP247/Core/Api/Controllers/AdminController.php'))) {
            $nameSpaceHome = 'App\GP247\Core\Api\Controllers';
        } else {
            $nameSpaceHome = 'GP247\Core\Api\Controllers';
        }
        Route::get('info', $nameSpaceHome.'\AdminController@getInfo');
    });


});
