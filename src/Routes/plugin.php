<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminPluginsController.php'))) {
    $nameSpaceAdminPlugin = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminPlugin = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'plugin'], function () use ($nameSpaceAdminPlugin) {
    //Process import
    Route::get('/import', $nameSpaceAdminPlugin.'\AdminPluginsController@importExtension')
        ->name('admin_plugin.import');
    Route::post('/import', $nameSpaceAdminPlugin.'\AdminPluginsController@processImport')
        ->name('admin_plugin.process_import');
    //End process
    
    Route::get('', $nameSpaceAdminPlugin.'\AdminPluginsController@index')
        ->name('admin_plugin.index');
    Route::post('/install', $nameSpaceAdminPlugin.'\AdminPluginsController@install')
        ->name('admin_plugin.install');
    Route::post('/uninstall', $nameSpaceAdminPlugin.'\AdminPluginsController@uninstall')
        ->name('admin_plugin.uninstall');
    Route::post('/enable', $nameSpaceAdminPlugin.'\AdminPluginsController@enable')
        ->name('admin_plugin.enable');
    Route::post('/disable', $nameSpaceAdminPlugin.'\AdminPluginsController@disable')
        ->name('admin_plugin.disable');

    if (config('gp247-config.admin.api_plugins')) {
        Route::get('/online', $nameSpaceAdminPlugin.'\AdminPluginsOnlineController@index')
        ->name('admin_plugin_online.index');
        Route::post('/install/online', $nameSpaceAdminPlugin.'\AdminPluginsOnlineController@install')
            ->name('admin_plugin_online.install');
        // Route register api license
        Route::post('/register-license', $nameSpaceAdminPlugin.'\AdminPluginsOnlineController@registerLicense')
            ->name('admin_plugin_online.register-license');
    }
});
