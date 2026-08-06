<?php
return [
    'core'                 => '2.0',
    'homepage'             => 'https://gp247.net',
    'name'                 => 'Core laravel admin for all systems',
    'github'               => 'https://github.com/gp247net/core',
    'facebook'             => 'https://www.facebook.com/GP247.official',
    'auth'                 => 'GP247 Team',
    'email'                => 'gp247.net@gmail.com',

    // Ecosystem fingerprint (header + <meta generator>). Default on so every
    // GP247 site contributes to public technology-usage statistics. Set
    // GP247_FINGERPRINT=false to hide it (white-label / privacy). Versionless
    // by design — see GP247\Core\Middleware\Fingerprint.
    'fingerprint'          => env('GP247_FINGERPRINT', true),
];
