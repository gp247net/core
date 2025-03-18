<?php

use Illuminate\Support\Facades\Route;

// Admin routes
Route::group(
    [
        'prefix' => GP247_ADMIN_PREFIX,
        'middleware' => GP247_ADMIN_MIDDLEWARE,
    ],
    function () {

        //Load admin from core
        foreach (glob(__DIR__ . '/Routes/Admin/*.php') as $filename) {
            $this->loadRoutesFrom($filename);
        }


        //Load admin from shop
        foreach (glob(__DIR__ . '/../../shop/src/Routes/Admin/*.php') as $filename) {
            $this->loadRoutesFrom($filename);
        }

        //Load admin from front
        foreach (glob(__DIR__ . '/../../front/src/Routes/Admin/*.php') as $filename) {
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
    //Api core
    Route::group(
        [
            'middleware' => GP247_API_MIDDLEWARE,
            'prefix' => GP247_API_CORE_PREFIX,
        ],
        function () {

            //Load api from core
            foreach (glob(__DIR__ . '/Routes/Api/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }

            //Load api from shop
            foreach (glob(__DIR__ . '/../../shop/src/Routes/Api/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }

            //Load api from front
            foreach (glob(__DIR__ . '/../../front/src/Routes/Api/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }

        }
    );

    //Api front
    Route::group(
        [
            'middleware' => GP247_API_MIDDLEWARE,
            'prefix' => 'api',
        ],
        function () {

            //Load api from shop
            foreach (glob(__DIR__ . '/../../shop/src/Routes/Api/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }

            //Load api from front
            foreach (glob(__DIR__ . '/../../front/src/Routes/Api/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }

        }
    );
}


if(defined('GP247_FRONT_MIDDLEWARE')){
        $langUrl = GP247_SEO_LANG ?'{lang?}/' : '';
        $suffix = GP247_SUFFIX_URL;

        if (file_exists(app_path('GP247/Front/Controllers/HomeController.php'))) {
            $nameSpaceHome = 'App\GP247\Front\Controllers';
        } else {
            $nameSpaceHome = 'GP247\Front\Controllers';
        }

        // Front routes
        Route::group(
            [
            'middleware' => GP247_FRONT_MIDDLEWARE,
        ],
        function () use($langUrl, $suffix, $nameSpaceHome){

            //Load front from front default
            if(file_exists(__DIR__ . '/../../front/src/Routes/front_default.php')){
                $this->loadRoutesFrom(__DIR__ . '/../../front/src/Routes/front_default.php');
            }

            //Load front from shop
            foreach (glob(__DIR__ . '/../../shop/src/Routes/Front/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }


            //Load front from front
            foreach (glob(__DIR__ . '/../../front/src/Routes/Front/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }
    
            Route::get($langUrl.'{alias}'.$suffix, $nameSpaceHome.'\HomeController@pageDetailProcessFront')->name('front.page.detail');

        }
    );
}