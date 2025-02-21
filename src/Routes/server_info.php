<?php
use Illuminate\Support\Facades\Route;

if (file_exists(app_path('GP247/Core/Controllers/AdminServerInfoController.php'))) {
    $nameSpace = 'App\GP247\Core\Controllers';
} else {
    $nameSpace = 'GP247\Core\Controllers';
}

Route::get('server_info', $nameSpace.'\AdminServerInfoController@index')
->name('admin.server_info');