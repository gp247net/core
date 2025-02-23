<?php
if (file_exists(app_path('GP247/Core/Controllers/AdminCacheConfigController.php'))) {
    $nameSpaceAdminCacheConfig = 'App\GP247\Core\Controllers';
} else {
    $nameSpaceAdminCacheConfig = 'GP247\Core\Controllers';
}
Route::group(['prefix' => 'cache_config'], function () use ($nameSpaceAdminCacheConfig) {
    Route::get('/', $nameSpaceAdminCacheConfig.'\AdminCacheConfigController@index')->name('admin_cache_config.index');
    Route::post('/clear_cache', $nameSpaceAdminCacheConfig.'\AdminCacheConfigController@clearCache')->name('admin_cache_config.clear_cache');
});