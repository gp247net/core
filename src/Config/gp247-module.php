<?php
return [

    // Module display in left header admin
    'module_header_left' => [
        ['view' => 'gp247-core::component.language', 'sort' => 100], // path to view
        ['view' => 'gp247-core::component.admin_theme', 'sort' => 200], // path to view
    ],

    // Module display in right header admin
    'module_header_right' => [
        ['view' => 'gp247-core::component.home_button', 'sort' => 10], // path to view
        ['view' => 'gp247-core::component.notice', 'sort' => 100], // path to view
        ['view' => 'gp247-core::component.admin_profile', 'sort' => 200], // path to view
    ],

    //List block to homepage
    'homepage' => [
        ['view' => 'gp247-core::component.home_default', 'sort' => 100], // path to view
        ['view' => 'gp247-core::component.home_footer', 'sort' => 200], // path to view
    ],
];