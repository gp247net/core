<?php
if (file_exists(app_path('GP247/Core/Controllers/Auth/LoginController.php'))) {
    $nameSpaceAdminAuth = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminAuth = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'auth'], function () use ($nameSpaceAdminAuth) {
    Route::get('login', $nameSpaceAdminAuth . '\Auth\LoginController@getLogin')->name('admin.login');
    Route::post('login', $nameSpaceAdminAuth . '\Auth\LoginController@postLogin')->name('admin.post_login');
    Route::get('logout', $nameSpaceAdminAuth . '\Auth\LoginController@getLogout')->name('admin.logout');
    Route::get('setting', $nameSpaceAdminAuth . '\Auth\LoginController@getSetting')->name('admin.setting');
    Route::post('setting', $nameSpaceAdminAuth . '\Auth\LoginController@putSetting')->name('admin.post_setting');

    if (config('gp247-config.admin.forgot_password')) {
        Route::get('forgot', $nameSpaceAdminAuth .'\Auth\ForgotPasswordController@getForgot')->name('admin.forgot');
        Route::post('forgot', $nameSpaceAdminAuth .'\Auth\ForgotPasswordController@sendRepostForgotsetLinkEmail')->name('admin.post_forgot');
        Route::get('password/reset/{token}', $nameSpaceAdminAuth .'\Auth\ResetPasswordController@formResetPassword')->name('admin.password_reset');
        Route::post('password/reset', $nameSpaceAdminAuth .'\Auth\ResetPasswordController@reset')->name('admin.password_request');
    }
});