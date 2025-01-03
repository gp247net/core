<?php

namespace GP247\Core\Handlers;

class LfmConfigHandler extends \UniSharp\LaravelFilemanager\Handlers\ConfigHandler
{
    public function userField()
    {

        if (function_exists('gp247_process_private_folder')) {
            return gp247_process_private_folder();
        }
        return;
    }
}
