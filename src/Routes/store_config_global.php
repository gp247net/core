<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminConfigGlobalController.php'))) {
    $nameSpaceAdminStoreConfigGlobal = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminStoreConfigGlobal = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'config'], function () use ($nameSpaceAdminStoreConfigGlobal) {
    Route::get('/webhook', $nameSpaceAdminStoreConfigGlobal.'\AdminConfigGlobalController@webhook')->name('admin_config_global.webhook');
    Route::post('/update', $nameSpaceAdminStoreConfigGlobal.'\AdminConfigGlobalController@update')->name('admin_config_global.update');
});