<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminLogController.php'))) {
    $nameSpaceAdminLog = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminLog = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'log'], function () use ($nameSpaceAdminLog) {
    Route::get('/', $nameSpaceAdminLog.'\AdminLogController@index')->name('admin_log.index');
    Route::post('/delete', $nameSpaceAdminLog.'\AdminLogController@deleteList')->name('admin_log.delete');
});