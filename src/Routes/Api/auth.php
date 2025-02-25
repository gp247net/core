<?php
use Illuminate\Support\Facades\Route;


if (file_exists(app_path('GP247/Core/Api/Controllers/AdminAuthController.php'))) {
    $nameSpaceHome = 'App\GP247\Core\Api\Controllers';
} else {
    $nameSpaceHome = 'GP247\Core\Api\Controllers';
}
Route::post('login', $nameSpaceHome.'\AdminAuthController@login');

Route::group([
    'middleware' => [
        'auth:admin-api', 
        config('gp247-config.api.auth.api_scope_type_admin').':'.config('gp247-config.api.auth.api_scope_admin')
    ]
], function () {
    if (file_exists(app_path('GP247/Core/Api/Controllers/AdminAuthController.php'))) {
        $nameSpaceHome = 'App\GP247\Core\Api\Controllers';
    } else {
        $nameSpaceHome = 'GP247\Core\Api\Controllers';
    }
    Route::get('logout', $nameSpaceHome.'\AdminAuthController@logout');


    if (file_exists(app_path('GP247/Core/Api/Controllers/AdminController.php'))) {
        $nameSpaceHome = 'App\GP247\Core\Api\Controllers';
    } else {
        $nameSpaceHome = 'GP247\Core\Api\Controllers';
    }
    Route::get('info', $nameSpaceHome.'\AdminController@getInfo');
});
