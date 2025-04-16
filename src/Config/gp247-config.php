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

         // Default, all tables have prefix GP247_DB_PREFIX can be customized add new fields.
        'schema_customize' => env('GP247_ADMIN_SCHEMA_CUSTOMIZE', ''), //List tables can be customized add new fields, ex: 'table1,table2'

        //Config for extension
        'extension' => [
            'extension_protected' => [
                'Plugins' => explode(',', env('GP247_PROTECTED_PLUGINS', '')), // List plugins cannot remove, ex: 'Plugin1','Plugin2'
                'Templates' => explode(',', env('GP247_PROTECTED_TEMPLATES', '')), // List templates cannot remove, ex: 'Template1','Template2'
            ],
        
        ],

        // Middleware for admin
        // Sort order of middleware is important, do not change the order.
        'middleware'  => [
            1        => 'admin.auth',
            2        => 'admin.permission',
            3        => 'admin.pjax',
            4        => 'admin.log',
            5        => 'admin.storeId',
            6        => 'localization',
            // 7        => 'admin.session',
        ],

    ],

    //Config for api
    'api' => [
        'auth' => [
            'api_remmember' => env('GP247_API_RECOMMEMBER', 30), //days - expires_at
            'api_token_expire_default' => env('GP247_API_TOKEN_EXPIRE_DEFAULT', 7), //days - expires_at default
            'api_remmember_admin' => env('GP247_API_RECOMMEMBER_ADMIN', 30), //days - expires_at
            'api_token_expire_admin_default' => env('GP247_API_TOKEN_EXPIRE_ADMIN_DEFAULT', 7), //days - expires_at default
            'api_scope_type' => env('GP247_API_SCOPE_TYPE', 'ability'), //ability|abilities
            'api_scope_type_admin' => env('GP247_API_SCOPE_TYPE_ADMIN', 'ability'), //ability|abilities
            'api_scope_user' => env('GP247_API_SCOPE_USER', 'user'), //string, separated by commas
            'api_scope_user_guest' => env('GP247_API_SCOPE_USER_GUEST', 'user-guest'), //string, separated by commas
            'api_scope_admin' => env('GP247_API_SCOPE_ADMIN', 'admin-supper'),//string, separated by commas
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
        'GP247_LIBRARY_API'   => env('GP247_LIBRARY_API', 'https://api.gp247.net/api/v1'),
        'GP247_API_MODE'      => env('GP247_API_MODE', 1), // 1: active, 0: deactive - prevent provide api service, as your-domain/api/service...
        'GP247_DB_PREFIX'     => env('GP247_DB_PREFIX', 'gp247_'), //Cannot change after install gp247
        'GP247_DB_CONNECTION' => env('DB_CONNECTION', 'mysql'), 
        'GP247_ADMIN_PREFIX'  => env('GP247_ADMIN_PREFIX', 'gp247_admin'), //Prefix url admin, ex: domain.com/gp247_admin
        'GP247_API_LISENCE'   => env('GP247_API_LISENCE', ''), //License key use connect to API
    ]

];
