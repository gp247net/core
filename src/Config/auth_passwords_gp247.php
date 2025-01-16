<?php
return [
    'admin_password' => [
        'provider' => 'admin_provider',
        'table'    => config('gp247-config.env.GP247_DB_PREFIX').'admin_password_resets',
        'expire'   => 60,
    ],
];
