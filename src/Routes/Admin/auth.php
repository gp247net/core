<?php

use GP247\Core\Controllers\Auth\ForgotPasswordController;
use GP247\Core\Controllers\Auth\LoginController;
use GP247\Core\Controllers\Auth\ResetPasswordController;

$loginController = gp247_namespace(LoginController::class);
Route::group(['prefix' => 'auth'], function () use ($loginController) {
    Route::get('login', $loginController . '@getLogin')->name('admin.login');
    Route::post('login', $loginController . '@postLogin')->name('admin.post_login');
    Route::get('logout', $loginController . '@getLogout')->name('admin.logout');
    Route::get('setting', $loginController . '@getSetting')->name('admin.setting');
    Route::post('setting', $loginController . '@putSetting')->name('admin.post_setting');

    if (config('gp247-config.admin.forgot_password')) {
        $forgotController = gp247_namespace(ForgotPasswordController::class);
        $resetController = gp247_namespace(ResetPasswordController::class);
        Route::get('forgot', $forgotController . '@getForgot')->name('admin.forgot');
        Route::post('forgot', $forgotController . '@sendRepostForgotsetLinkEmail')->name('admin.post_forgot');
        Route::get('password/reset/{token}', $resetController . '@formResetPassword')->name('admin.password_reset');
        Route::post('password/reset', $resetController . '@reset')->name('admin.password_request');
    }
});
