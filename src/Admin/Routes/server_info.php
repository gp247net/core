<?php
use Illuminate\Support\Facades\Route;

if (file_exists(app_path('GP247/Core/Admin/Controllers/AdminServerInfoController.php'))) {
    $nameSpace = 'App\GP247\Core\Admin\Controllers';
} else {
    $nameSpace = 'GP247\Core\Admin\Controllers';
}

Route::get('server_info', $nameSpace.'\AdminServerInfoController@index')
->name('admin.server_info');