<?php
if (file_exists(app_path('GP247/Core/Admin/Controllers/AdminTemplateController.php'))) {
    $nameSpaceAdminTemplate = 'App\GP247\Core\Admin\Controllers';
} else {
    $nameSpaceAdminTemplate = 'GP247\Core\Admin\Controllers';
}
Route::group(['prefix' => 'template'], function () use ($nameSpaceAdminTemplate) {
    //Process import
    Route::get('/import', $nameSpaceAdminTemplate.'\AdminTemplateController@importExtension')
        ->name('admin_template.import');
    Route::post('/import', $nameSpaceAdminTemplate.'\AdminTemplateController@processImport')
        ->name('admin_template.process_import');
    //End process

    Route::get('/', $nameSpaceAdminTemplate.'\AdminTemplateController@index')->name('admin_template.index');
    Route::post('install', $nameSpaceAdminTemplate.'\AdminTemplateController@install')->name('admin_template.install');
    Route::post('uninstall', $nameSpaceAdminTemplate.'\AdminTemplateController@uninstall')->name('admin_template.uninstall');
    Route::post('enable', $nameSpaceAdminTemplate.'\AdminTemplateController@enable')->name('admin_template.enable');
    Route::post('disable', $nameSpaceAdminTemplate.'\AdminTemplateController@disable')->name('admin_template.disable');

    if (config('gp247-config.admin.api_template')) {
        Route::get('/online', $nameSpaceAdminTemplate.'\AdminTemplateOnlineController@index')->name('admin_template_online.index');
        Route::post('/online/install', $nameSpaceAdminTemplate.'\AdminTemplateOnlineController@install')
        ->name('admin_template_online.install');
    }
});