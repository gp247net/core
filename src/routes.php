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


        $adminHomeController = gp247_namespace(\GP247\Core\Controllers\HomeController::class);
        // Cutover (PA-1): the admin landing renders the modern TailAdmin dashboard
        // in-place at the legacy `/` URL (keeps route name + URI for RBAC). The
        // full-page Livewire Dashboard has no permission gate, mirroring the legacy
        // home. HomeController@default/deny/... stay on the controller below.
        Route::get('/', gp247_namespace(\GP247\Core\AdminShell\Http\Livewire\Dashboard::class))->name('admin.home');
        Route::get('/default', $adminHomeController.'@default')->name('admin.default');
        Route::get('/deny', $adminHomeController.'@deny')->name('admin.deny');
        Route::get('/data_not_found', $adminHomeController.'@dataNotFound')->name('admin.data_not_found');
        Route::get('/deny_single', $adminHomeController.'@denySingle')->name('admin.deny_single');

        //Language
        Route::get('locale/{code}', function ($code) {
            session(['locale' => $code]);
            return back();
        })->name('admin.locale');
    }
);


// Route api — core registers only its own files; front, shop and plugins register theirs
// through the same seam from their service providers (US-CMP-api-package-route-registration).
foreach (array_merge(glob(__DIR__ . '/Routes/Api/*.php'), glob(__DIR__ . '/Routes/Api/v2/*.php')) as $filename) {
    \GP247\Core\Api\Support\ApiRouteRegistrar::register($filename);
}


if(defined('GP247_FRONT_MIDDLEWARE')){
        $langUrl = GP247_SEO_LANG ?'{lang?}/' : '';
        $suffix = GP247_SUFFIX_URL;

        // WHY a string, not ::class: core must not reference gp247/front classes
        // (NFR-MAINT-001); GP247_FRONT_MIDDLEWARE is only defined when front is installed.
        $frontHomeController = gp247_namespace('GP247\Front\Controllers\HomeController');

        // Front routes
        Route::group(
            [
            'middleware' => GP247_FRONT_MIDDLEWARE,
        ],
        function () use($langUrl, $suffix, $frontHomeController){

            //Load front from front default
            if(file_exists(__DIR__ . '/../../front/src/Routes/front_default.php')){
                $this->loadRoutesFrom(__DIR__ . '/../../front/src/Routes/front_default.php');
            }

            //Load front from shop
            foreach (glob(__DIR__ . '/../../shop/src/Routes/Front/*.php') as $filename) {
                $this->loadRoutesFrom($filename);
            }

            Route::get($langUrl.'{alias}'.$suffix, $frontHomeController.'@pageDetailProcessFront')->name('front.page.detail');

        }
    );
}