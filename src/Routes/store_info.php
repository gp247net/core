<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminStoreInfoController.php'))) {
    $nameSpaceAdminStoreInfo = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminStoreInfo = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'store_info'], function () use ($nameSpaceAdminStoreInfo) {
    Route::get('/', $nameSpaceAdminStoreInfo.'\AdminStoreInfoController@index')->name('admin_store.index');
    Route::post('/update_info', $nameSpaceAdminStoreInfo.'\AdminStoreInfoController@updateInfo')->name('admin_store.update');
});