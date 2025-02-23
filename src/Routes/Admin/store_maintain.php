<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminStoreMaintainController.php'))) {
    $nameSpaceAdminStoreMaintain = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminStoreMaintain = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'store_maintain'], function () use ($nameSpaceAdminStoreMaintain) {
    Route::get('/', $nameSpaceAdminStoreMaintain.'\AdminStoreMaintainController@index')->name('admin_store_maintain.index');
    Route::post('/', $nameSpaceAdminStoreMaintain.'\AdminStoreMaintainController@postEdit');
});
