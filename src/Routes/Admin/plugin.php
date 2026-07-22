<?php

use GP247\Core\Controllers\AdminPluginsController;
use GP247\Core\Controllers\AdminPluginsOnlineController;

$pluginController = gp247_namespace(AdminPluginsController::class);
Route::group(['prefix' => 'plugin'], function () use ($pluginController) {
    //Process import
    Route::get('/import', $pluginController.'@importExtension')
        ->name('admin_plugin.import');
    Route::post('/import', $pluginController.'@processImport')
        ->name('admin_plugin.process_import');
    //End process

    Route::get('', $pluginController.'@index')
        ->name('admin_plugin.index');
    Route::post('/install', $pluginController.'@install')
        ->name('admin_plugin.install');
    Route::post('/uninstall', $pluginController.'@uninstall')
        ->name('admin_plugin.uninstall');
    Route::post('/enable', $pluginController.'@enable')
        ->name('admin_plugin.enable');
    Route::post('/disable', $pluginController.'@disable')
        ->name('admin_plugin.disable');

    if (config('gp247-config.admin.api_plugins')) {
        $pluginOnlineController = gp247_namespace(AdminPluginsOnlineController::class);
        Route::get('/online', $pluginOnlineController.'@index')
        ->name('admin_plugin_online.index');
        Route::post('/install/online', $pluginOnlineController.'@install')
            ->name('admin_plugin_online.install');
        Route::post('/update/online', $pluginOnlineController.'@update')
            ->name('admin_plugin_online.update');
        Route::post('/check-update/online', $pluginOnlineController.'@checkUpdate')
            ->name('admin_plugin_online.check-update');
        // Per-plugin license (paid extensions) — distinct from the API license below
        Route::post('/save-license/online', $pluginOnlineController.'@saveLicense')
            ->name('admin_plugin_online.save-license');
        // Route register api license
        Route::post('/register-license', $pluginOnlineController.'@registerLicense')
            ->name('admin_plugin_online.register-license');
    }
});
