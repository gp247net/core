<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminPasswordPolicyController.php'))) {
    $nameSpaceAdminStoreConfig = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminStoreConfig = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'password_policy'], function () use ($nameSpaceAdminStoreConfig) {
    Route::get('/', $nameSpaceAdminStoreConfig.'\AdminPasswordPolicyController@index')->name('admin_password_policy.index');
    Route::post('/update', $nameSpaceAdminStoreConfig.'\AdminPasswordPolicyController@update')->name('admin_password_policy.update');
});