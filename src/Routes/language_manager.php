<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminLanguageManagerController.php'))) {
    $nameSpaceAdminLangManager = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminLangManager = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'language_manager'], function () use ($nameSpaceAdminLangManager) {
    Route::get('/', $nameSpaceAdminLangManager.'\AdminLanguageManagerController@index')->name('admin_language_manager.index');
    Route::post('/update', $nameSpaceAdminLangManager.'\AdminLanguageManagerController@postUpdate')->name('admin_language_manager.update');
    Route::get('/add', $nameSpaceAdminLangManager.'\AdminLanguageManagerController@add')->name('admin_language_manager.add');
    Route::post('/add', $nameSpaceAdminLangManager.'\AdminLanguageManagerController@postAdd');
});