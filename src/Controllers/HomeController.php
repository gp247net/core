<?php

namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use Illuminate\Http\Request;
use GP247\Core\Models\AdminHome;

class HomeController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }
    
    public function index(Request $request)
    {
        $blockDashboard = AdminHome::getBlockHome();
        $data                   = [];
        $data['blockDashboard'] = $blockDashboard;
        $data['title']          = gp247_language_render('admin.home');
        return view('gp247-core::home', $data);
    }

    public function default()
    {
        $data['title'] = gp247_language_render('admin.home');
        return view('gp247-core::default', $data);
    }

    /**
     * Page not found
     *
     * @return  [type]  [return description]
     */
    public function dataNotFound()
    {
        $data = [
            'title' => gp247_language_render('display.data_not_found'),
            'url' => session('url'),
        ];
        return view('gp247-core::data_not_found', $data);
    }


    /**
     * Page deny
     *
     * @return  [type]  [return description]
     */
    public function deny()
    {
        $data = [
            'title' => gp247_language_render('admin.deny'),
            'method' => session('method'),
            'url' => session('url'),
        ];
        return view('gp247-core::deny', $data);
    }

    /**
     * [denySingle description]
     *
     * @return  [type]  [return description]
     */
    public function denySingle()
    {
        $data = [
            'method' => session('method'),
            'url' => session('url'),
        ];
        return view('gp247-core::deny_single', $data);
    }
}
