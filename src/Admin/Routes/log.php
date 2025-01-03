<?php
if (file_exists(app_path('GP247/Core/Admin/Controllers/AdminLogController.php'))) {
    $nameSpaceAdminLog = 'App\GP247\Core\Admin\Controllers';
} else {
    $nameSpaceAdminLog = 'GP247\Core\Admin\Controllers';
}
Route::group(['prefix' => 'log'], function () use ($nameSpaceAdminLog) {
    Route::get('/', $nameSpaceAdminLog.'\AdminLogController@index')->name('admin_log.index');
    Route::post('/delete', $nameSpaceAdminLog.'\AdminLogController@deleteList')->name('admin_log.delete');
});