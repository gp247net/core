<?php
if (file_exists(app_path('GP247/Core/Admin/Controllers/AdminStoreMaintainController.php'))) {
    $nameSpaceAdminStoreMaintain = 'App\GP247\Core\Admin\Controllers';
} else {
    $nameSpaceAdminStoreMaintain = 'GP247\Core\Admin\Controllers';
}
Route::group(['prefix' => 'store_maintain'], function () use ($nameSpaceAdminStoreMaintain) {
    Route::get('/', $nameSpaceAdminStoreMaintain.'\AdminStoreMaintainController@index')->name('admin_store_maintain.index');
    Route::post('/', $nameSpaceAdminStoreMaintain.'\AdminStoreMaintainController@postEdit');
});
