<?php
namespace GP247\Core\Admin\Controllers;

use App\Http\Controllers\Controller;

class RootAdminController extends Controller
{
    public function __construct()
    {
        //
    }

    public function procesListAction(array $arrAction) {
        if (count($arrAction)) {
            $action = '<div class="td-action dropdown show">
            <a class="dropdown-toggle" href="#" role="button" id="dropdownMenuLink" data-toggle="dropdown" aria-haspopup="true" aria-expanded="true">
                <i class="fas fa-ellipsis-v"></i>
            </a>
            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuLink">
                ' . implode('', $arrAction) . '
            </div>
            </div>';
        } else {
            $action = '';
        }
        return $action;
    }

}
