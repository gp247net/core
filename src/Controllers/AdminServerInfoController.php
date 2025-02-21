<?php

namespace GP247\Core\Controllers;

use Illuminate\Http\Request;
use Composer\InstalledVersions;
use GP247\Core\Controllers\RootAdminController;
class AdminServerInfoController extends RootAdminController
{
    public function index()
    {
        $data = [
            'title' => gp247_language_render('admin.server_info'),
            'subTitle' => '',
            'icon' => 'fa fa-info-circle',
        ];

        // Get PHP information
        $phpInfo = [
            'version' => PHP_VERSION,
            'os' => PHP_OS,
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? '',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
        ];

        // Get loaded extensions
        $extensions = get_loaded_extensions();
        sort($extensions);

        $data['phpInfo'] = $phpInfo;
        $data['extensions'] = $extensions;
        $data['packages'] = gp247_composer_get_package_installed();

        return view('gp247-core::screen.server_info', $data);
    }
} 