<?php

namespace GP247\Core\Controllers;

use GP247\Core\Controllers\RootAdminController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use GP247\Core\Models\AdminHome;

class HomeController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        // Strangler (US-AUI-009): the admin landing is the modern TailAdmin
        // dashboard when the admin-shell route is registered; fall back to the
        // legacy block home only if admin-shell is unavailable.
        if (Route::has('gp247.admin-shell.dashboard')) {
            return redirect(gp247_route_admin('gp247.admin-shell.dashboard'));
        }

        $blockDashboard = AdminHome::getBlockHome();
        $data                   = [];
        $data['blockDashboard'] = $blockDashboard;
        $data['title']          = gp247_language_render('admin.home');
        return view('gp247-admin::home', $data);
    }

    public function default()
    {
        $data['title'] = gp247_language_render('admin.home');
        return view('gp247-admin::default', $data);
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
        return view('gp247-admin::data_not_found', $data);
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
        return view('gp247-admin::deny', $data);
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
        return view('gp247-admin::deny_single', $data);
    }
}
