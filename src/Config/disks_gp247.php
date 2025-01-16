<?php
return [
    'gp247' => [
        'driver'     => 'local',
        'root' => storage_path('/app/public'),
        'url'        => '/storage',
        'visibility' => 'public',
        'throw' => false,
    ],
    'tmp' => [
        'driver'     => 'local',
        'root'       => storage_path('tmp'),
        'url'        => '',
    ],
];
