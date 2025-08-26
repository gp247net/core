<?php
return [
    'admin' => [
        'driver'   => 'session',
        'provider' => 'admin_provider',
    ],
    'admin-api' => [
        'driver'   => 'sanctum',
        'provider' => 'admin_provider',
    ],
];
