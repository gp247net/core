<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminLanguageController.php'))) {
    $nameSpaceAdminLang = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminLang = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'language'], function () use ($nameSpaceAdminLang) {
    Route::get('/', $nameSpaceAdminLang.'\AdminLanguageController@index')->name('admin_language.index');
    Route::get('create', function () {
        return redirect()->route('admin_language.index');
    });
    Route::post('/create', $nameSpaceAdminLang.'\AdminLanguageController@postCreate')->name('admin_language.create');
    Route::get('/edit/{id}', $nameSpaceAdminLang.'\AdminLanguageController@edit')->name('admin_language.edit');
    Route::post('/edit/{id}', $nameSpaceAdminLang.'\AdminLanguageController@postEdit')->name('admin_language.post_edit');
    Route::post('/delete', $nameSpaceAdminLang.'\AdminLanguageController@deleteList')->name('admin_language.delete');
});