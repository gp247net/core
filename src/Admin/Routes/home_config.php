<?php
if (file_exists(app_path('GP247/Core/Admin/Controllers/AdminHomeConfigController.php'))) {
    $nameSpaceAdminHome = 'App\GP247\Core\Admin\Controllers';
} else {
    $nameSpaceAdminHome = 'GP247\Core\Admin\Controllers';
}
Route::group(['prefix' => 'admin_home_config'], function () use ($nameSpaceAdminHome) {
    Route::get('/', $nameSpaceAdminHome.'\AdminHomeConfigController@index')->name('admin_home_config.index');
    Route::get('create', function () {
        return redirect()->route('admin_home_config.index');
    });
    Route::post('/create', $nameSpaceAdminHome.'\AdminHomeConfigController@postCreate')->name('admin_home_config.create');
    Route::get('/edit/{id}', $nameSpaceAdminHome.'\AdminHomeConfigController@edit')->name('admin_home_config.edit');
    Route::post('/edit/{id}', $nameSpaceAdminHome.'\AdminHomeConfigController@postEdit')->name('admin_home_config.post_edit');
    Route::post('/delete', $nameSpaceAdminHome.'\AdminHomeConfigController@deleteList')->name('admin_home_config.delete');
});