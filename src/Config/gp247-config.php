<?php
return [
    'admin' => [
        //Enable, disable page libary online
        'api_plugins'      => env('GP247_ADMIN_API_PLUGIN', 1),
        'api_templates'    => env('GP247_ADMIN_API_TEMPLATE', 1),

        //Config log access admin
        'admin_log_except'    => env('GP247_ADMIN_LOG_EXCEPT', ''), //Except log
        'admin_log'           => env('GP247_ADMIN_LOG', 1), //Log access admin

        'forgot_password'     => env('GP247_ADMIN_FORGOT_PASSWORD', 1), //Enable feature forgot password

        // Seam: admin store-context resolver. Default null = pin ROOT (single-store
        // behaviour unchanged, no query). The MultiStore Pro edition runtime-appends
        // a callable here (Provider.php, when active) that resolves + permission-checks
        // the selected store. ADR multi-store_admin-store-scope-seam.
        'store_resolver'      => null,
        // Seam: header widgets. Default [] = header unchanged. Plugins runtime-append
        // Livewire component FQCNs (e.g. Pro store switcher). ADR admin-shell_header-widget-registry.
        'header_widgets'      => [],
        // Seam: admin action fence. Default null = no fence (authorization unchanged).
        // A plugin runtime-appends a callable (AdminUserContract $user, ?string $screenUri,
        // string $action): ?string — return a deny reason to VETO the action BEFORE the RBAC
        // authorizer runs (so it also binds administrators), or null to leave the decision to
        // RBAC. A veto can only add "deny", never "allow"; a throwing fence fails closed.
        // Read at call time (order-independent of provider boot). ADR admin-shell_action-fence-seam.
        'action_fence'        => null,

         // Default, all tables have prefix GP247_DB_PREFIX can be customized add new fields.
        'schema_customize' => env('GP247_ADMIN_SCHEMA_CUSTOMIZE', ''), //List tables can be customized add new fields, ex: 'table1,table2'

        //Config for extension
        'extension' => [
            'extension_protected' => [
                'Plugins' => explode(',', env('GP247_PROTECTED_PLUGINS', '')), // List plugins cannot remove, ex: 'Plugin1','Plugin2'
                'Templates' => explode(',', env('GP247_PROTECTED_TEMPLATES', '')), // List templates cannot remove, ex: 'Template1','Template2'
            ],
            // Cache lifetime (seconds) of the marketplace check-update result
            'update_check_ttl' => env('GP247_EXTENSION_UPDATE_CHECK_TTL', 21600),
            // Verify the marketplace API's TLS certificate on check-update/download.
            // Safe default = true (production). Set GP247_UPDATE_VERIFY_SSL=false only
            // for local/dev marketplaces that use a self-signed certificate.
            'update_verify_ssl' => env('GP247_UPDATE_VERIFY_SSL', true),
        ],

        // Middleware for admin
        // Sort order of middleware is important, do not change the order.
        'middleware'  => [
            1        => 'admin.auth',
            2        => 'admin.permission',
            3        => 'admin.log',
            4        => 'admin.storeId',
            5        => 'localization',
            // 6        => 'admin.session',
        ],

    ],

    //Config for mail queue runner
    'mail' => [
        // Auto-register a scheduled `queue:work` drain so a single standard
        // `schedule:run` cron is enough to send queued mail on shared hosting
        // (no persistent worker needed). Safe default = true. Set
        // GP247_SCHEDULE_QUEUE_WORK=false when a persistent worker already drains
        // the queue (supervisor / Docker queue service) to avoid a redundant
        // per-minute process. Never registered while QUEUE_CONNECTION=sync.
        'schedule_queue_worker' => env('GP247_SCHEDULE_QUEUE_WORK', true),
    ],

    //Config for api
    'api' => [
        'auth' => [
            'api_remmember' => env('GP247_API_RECOMMEMBER', 30), //days - expires_at
            'api_token_expire_default' => env('GP247_API_TOKEN_EXPIRE_DEFAULT', 7), //days - expires_at default
            'api_scope_user' => env('GP247_API_SCOPE_USER', 'user'), //string, separated by commas
            'api_scope_user_guest' => env('GP247_API_SCOPE_USER_GUEST', 'user-guest'), //string, separated by commas

            'api_scope_admin' => env('GP247_API_SCOPE_ADMIN', 'admin'),//string, separated by commas
            'api_scope_admin_supper' => env('GP247_API_SCOPE_ADMIN_SUPPER', 'admin-supper'),//string, separated by commas
            'api_remmember_admin' => env('GP247_API_RECOMMEMBER_ADMIN', 30), //days - expires_at
            'api_token_expire_admin_default' => env('GP247_API_TOKEN_EXPIRE_ADMIN_DEFAULT', 7), //days - expires_at default
            
        ],

        // Middleware for api
        // Sort order of middleware is important, do not change the order.
        'middleware' => [
            1        => 'json.response',
            2        => 'api.connection',
            3        => 'throttle: 1000',
        ],
    ],

    //Config for env
    'env' => [
        'GP247_ACTIVE'        => env('GP247_ACTIVE', 1), // 1: active, 0: deactive - prevent load vencore package
        'GP247_LIBRARY_API'   => env('GP247_LIBRARY_API', 'https://api.gp247.net/api/v2'),
        'GP247_API_MODE'      => env('GP247_API_MODE', 1), // 1: active, 0: deactive - prevent provide api service, as your-domain/api/service...
        'GP247_DB_PREFIX'     => env('GP247_DB_PREFIX', 'gp247_'), //Cannot change after install gp247
        'GP247_DB_CONNECTION' => env('DB_CONNECTION', 'mysql'), 
        'GP247_ADMIN_PREFIX'  => env('GP247_ADMIN_PREFIX', 'gp247_admin'), //Prefix url admin, ex: domain.com/gp247_admin
        'GP247_API_LICENSE'   => env('GP247_API_LICENSE', ''), //License key use connect to API
    ]

];
