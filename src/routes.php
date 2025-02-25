<?php

use Illuminate\Support\Facades\Route;

// Admin routes
Route::group(
    [
        'prefix' => GP247_ADMIN_PREFIX,
        'middleware' => GP247_ADMIN_MIDDLEWARE,
    ],
    function () {

        foreach (glob(__DIR__ . '/Routes/Admin/*.php') as $filename) {
            $this->loadRoutesFrom($filename);
        }
        

        if (file_exists(app_path('GP247/Core/Controllers/HomeController.php'))) {
            $nameSpaceHome = 'App\GP247\Core\Controllers';
        } else {
            $nameSpaceHome = 'GP247\Core\Controllers';
        }
        Route::get('/', $nameSpaceHome.'\HomeController@index')->name('admin.home');
        Route::get('/default', $nameSpaceHome.'\HomeController@default')->name('admin.default');
        Route::get('/deny', $nameSpaceHome.'\HomeController@deny')->name('admin.deny');
        Route::get('/data_not_found', $nameSpaceHome.'\HomeController@dataNotFound')->name('admin.data_not_found');
        Route::get('/deny_single', $nameSpaceHome.'\HomeController@denySingle')->name('admin.deny_single');

        //Language
        Route::get('locale/{code}', function ($code) {
            session(['locale' => $code]);
            return back();
        })->name('admin.locale');
    }
);


// Route api admin
if (config('gp247-config.env.GP247_API_MODE')) {
    Route::group(
        [
            'middleware' => GP247_API_MIDDLEWARE,
            'prefix' => GP247_API_PREFIX,
        ],
        function () {
            foreach (glob(__DIR__ . '/Routes/Api/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }
        }
    );
}