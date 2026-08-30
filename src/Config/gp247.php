<?php
return [
    'core'                 => '3.0',
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

    // Environment identity badge shown in the admin shell so an operator can tell
    // at a glance which environment they are working in — a visual guard against
    // running destructive actions on production while believing they are on a
    // sandbox (security concern, not just cosmetics).
    //
    // BINARY BY DESIGN: GP247/Laravel only treat the EXACT string "production"
    // specially — CoreServiceProvider forces `app.debug` off on it, and Laravel's
    // artisan guard blocks destructive commands only on it. Every other env name
    // (local/dev/staging/anything) is behaviourally identical, so distinguishing
    // them here would be a cosmetic lie. Hence just two profiles: `production`
    // (neutral gray, so day-to-day work does not look alarming) and
    // `non_production` (a single COLORED style for everything else, so a live
    // site is never mistaken for a sandbox). The non-production label is the raw
    // APP_ENV value, upper-cased, so the operator still sees which env it is.
    //
    // `color` must be a Tailwind color name already covered by the admin-shell
    // safelist (tailwind.config.js) — gray/amber/red… — so no CSS rebuild is
    // needed. `label` is passed through gp247_language_render(), so it may be a
    // plain string or an admin language key. Site owners can override these
    // profiles from the host config to relabel/recolor/hide.
    'env_badge'            => [
        'enable'         => env('GP247_ENV_BADGE', true),
        'production'     => ['label' => 'PRODUCTION', 'color' => 'gray',  'icon' => 'fas fa-circle-check'],
        'non_production' => ['color' => 'amber', 'icon' => 'fas fa-triangle-exclamation'],

        // Chip shown whenever APP_DEBUG is on, independently of APP_ENV. This is
        // the actually security-critical signal: debug mode leaks stack traces,
        // env vars and SQL on error pages, and a "production" env can still run
        // with debug on. Rendered in the header (always visible while working);
        // kept red on purpose. Set to null to hide it.
        'debug'          => ['label' => 'DEBUG ON', 'color' => 'red', 'icon' => 'fas fa-bug'],
    ],
];
