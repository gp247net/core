<?php

namespace GP247\Core\Api\Controllers;

use GP247\Core\Admin\Controllers\RootAdminController;
use Illuminate\Http\Request;

class AdminController extends RootAdminController
{

    /**
     * Get the authenticated User
     *
     * @return [json] user object
     */
    public function getInfo(Request $request)
    {
        return response()->json($request->user());
    }
}
