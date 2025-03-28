<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminCustomFieldController.php'))) {
    $nameSpaceAdminCustomField = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminCustomField = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'custom_field'], function () use ($nameSpaceAdminCustomField) {
    Route::get('/', $nameSpaceAdminCustomField.'\AdminCustomFieldController@index')->name('admin_custom_field.index');
    Route::get('create', function () {
        return redirect()->route('admin_custom_field.index');
    });
    Route::post('/create', $nameSpaceAdminCustomField.'\AdminCustomFieldController@postCreate')->name('admin_custom_field.create');
    Route::get('/edit/{id}', $nameSpaceAdminCustomField.'\AdminCustomFieldController@edit')->name('admin_custom_field.edit');
    Route::post('/edit/{id}', $nameSpaceAdminCustomField.'\AdminCustomFieldController@postEdit')->name('admin_custom_field.post_edit');
    Route::post('/delete', $nameSpaceAdminCustomField.'\AdminCustomFieldController@deleteList')->name('admin_custom_field.delete');
});