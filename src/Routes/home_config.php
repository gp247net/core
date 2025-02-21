<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminHomeLayoutController.php'))) {
    $nameSpaceAdminHome = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminHome = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'admin_home_layout'], function () use ($nameSpaceAdminHome) {
    Route::get('/', $nameSpaceAdminHome.'\AdminHomeLayoutController@index')->name('admin_home_layout.index');
    Route::get('create', function () {
        return redirect()->route('admin_home_layout.index');
    });
    Route::post('/create', $nameSpaceAdminHome.'\AdminHomeLayoutController@postCreate')->name('admin_home_layout.create');
    Route::get('/edit/{id}', $nameSpaceAdminHome.'\AdminHomeLayoutController@edit')->name('admin_home_layout.edit');
    Route::post('/edit/{id}', $nameSpaceAdminHome.'\AdminHomeLayoutController@postEdit')->name('admin_home_layout.post_edit');
    Route::post('/delete', $nameSpaceAdminHome.'\AdminHomeLayoutController@deleteList')->name('admin_home_layout.delete');
});