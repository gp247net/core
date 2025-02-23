<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminStoreConfigController.php'))) {
    $nameSpaceAdminStoreConfig = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminStoreConfig = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'store_config'], function () use ($nameSpaceAdminStoreConfig) {
    Route::get('/', $nameSpaceAdminStoreConfig.'\AdminStoreConfigController@index')->name('admin_config.index');
    Route::post('/update', $nameSpaceAdminStoreConfig.'\AdminStoreConfigController@update')->name('admin_config.update');
    Route::post('/add_new', $nameSpaceAdminStoreConfig.'\AdminStoreConfigController@addNew')->name('admin_config.add_new');
    Route::post('/delete', $nameSpaceAdminStoreConfig.'\AdminStoreConfigController@delete')->name('admin_config.delete');
});