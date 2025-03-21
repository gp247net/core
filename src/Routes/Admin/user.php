<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminTemplateController.php'))) {
    $nameSpaceAdminUser = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminUser = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'user'], function () use ($nameSpaceAdminUser) {
    Route::get('/', $nameSpaceAdminUser.'\Auth\UsersController@index')->name('admin_user.index');
    Route::get('create', $nameSpaceAdminUser.'\Auth\UsersController@create')->name('admin_user.create');
    Route::post('/create', $nameSpaceAdminUser.'\Auth\UsersController@postCreate')->name('admin_user.post_create');
    Route::get('/edit/{id}', $nameSpaceAdminUser.'\Auth\UsersController@edit')->name('admin_user.edit');
    Route::post('/edit/{id}', $nameSpaceAdminUser.'\Auth\UsersController@postEdit')->name('admin_user.post_edit');
    Route::post('/delete', $nameSpaceAdminUser.'\Auth\UsersController@deleteList')->name('admin_user.delete');
});