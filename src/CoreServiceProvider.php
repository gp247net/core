<?php

namespace GP247\Core;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

use GP247\Core\Commands\MakePlugin;
use GP247\Core\Commands\Information;
use GP247\Core\Commands\Update;
use GP247\Core\Commands\Install;
use GP247\Core\Middleware\Localization;
use GP247\Core\Api\Middleware\ApiConnection;
use GP247\Core\Api\Middleware\ForceJsonResponse;
use GP247\Core\Middleware\Authenticate;
use GP247\Core\Middleware\LogOperation;
use GP247\Core\Middleware\Session;
use GP247\Core\Middleware\PermissionMiddleware;
use GP247\Core\Middleware\AdminStoreId;
use Spatie\Pjax\Middleware\FilterIfPjax;

use GP247\Core\Models\PersonalAccessToken;
use GP247\Core\Models\AdminStore;

class CoreServiceProvider extends ServiceProvider
{
    protected $listCommand = [
        MakePlugin::class,
        Information::class,
        Update::class,
    ];
    
    protected function initial()
    {
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'gp247');

        //Create directory
        try {
            if (!is_dir($directory = app_path('GP247/Plugins'))) {
                mkdir($directory, 0777, true);
            }

            if (!is_dir($directory = app_path('GP247/Helpers'))) {
                mkdir($directory, 0777, true);
            }

            if (!is_dir($directory = app_path('GP247/Core'))) {
                mkdir($directory, 0777, true);
            }

            if (!is_dir($directory = public_path('GP247'))) {
                mkdir($directory, 0777, true);
            }

            if (!is_dir($directory = public_path('vendor'))) {
                mkdir($directory, 0777, true);
            }

            if (!is_dir($directory = storage_path('tmp'))) {
                mkdir($directory, 0777, true);
            }

        } catch (\Throwable $e) {
            $msg = '#GP247:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
            echo $msg;
            exit;
        }

        //Load publish
        try {
            $this->registerPublishing();
        } catch (\Throwable $e) {
            $msg = '#GP247:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
            echo $msg;
            exit;
        }

        //Load command initial
        try {
            $this->commands([
                Install::class,
            ]);
        } catch (\Throwable $e) {
            $msg = '#GP247:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
            echo $msg;
            exit;
        }

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {

        $this->initial();

        if (GP247_ACTIVE == 1 && \Illuminate\Support\Facades\Storage::disk('local')->exists('gp247-installed.txt')) {

            //If env is production, then disable debug mode
            if (config('app.env') === 'production') {
                config(['app.debug' => false]);
            }
            
            Paginator::useBootstrap();
            Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

            //Load helper
            try {
                foreach (glob(__DIR__.'/Library/Helpers/*.php') as $filename) {
                    require_once $filename;
                }
            } catch (\Throwable $e) {
                $msg = '#GP247::core_helper_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            //Check connection
            try {
                DB::connection(GP247_DB_CONNECTION)->getPdo();
            } catch (\Throwable $e) {
                $msg = '#GP247::Pdo_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            //Boot process GP247
            try {
                $this->bootDefault();
            } catch (\Throwable $e) {

                $msg = '#GP247::core_default_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile().PHP_EOL;
                if (\Illuminate\Support\Facades\Storage::disk('local')->exists('gp247-installed.txt')) {
                    $msg .= "--> Try delete the file gp247-installed.txt in the ".\Illuminate\Support\Facades\Storage::disk('local')->path('gp247-installed.txt').', then re-install gp247'.PHP_EOL;
                }
                gp247_report($msg);
                echo $msg;
                exit;
            }

            //Route
            try {
                if (file_exists($routes = __DIR__.'/routes.php')) {
                    $this->loadRoutesFrom($routes);
                }
            } catch (\Throwable $e) {
                $msg = '#GP247::core_route_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            try {
                $this->registerRouteMiddleware();
            } catch (\Throwable $e) {
                $msg = '#GP247::core_middeware_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            try {
                $this->commands($this->listCommand);
            } catch (\Throwable $e) {
                $msg = '#GP247::core_command_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            try {
                $this->validationExtend();
            } catch (\Throwable $e) {
                $msg = '#GP247::core_validate_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            $this->loadViewsFrom(__DIR__.'/Views/admin', 'gp247-core');
            //Load Plugin Provider
            try {
                foreach (glob(app_path().'/GP247/Plugins/*/Provider.php') as $filename) {
                    require_once $filename;
                }
                foreach (glob(app_path().'/GP247/Plugins/*/Route.php') as $filename) {
                    $this->loadRoutesFrom($filename);
                }
            } catch (\Throwable $e) {
                $msg = '#GP247::plugin_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            //Load helper
            try {
                foreach (glob(app_path().'/GP247/Helpers/*.php') as $filename) {
                    require_once $filename;
                }
            } catch (\Throwable $e) {
                $msg = '#GP247::helper_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
                gp247_report($msg);
                echo $msg;
                exit;
            }

            $this->eventRegister();

        }
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //Note the order of precedence.
        //The previous config can be used in the following configg

        $this->mergeConfigFrom(__DIR__.'/Config/gp247.php', 'gp247');
        $this->mergeConfigFrom(__DIR__.'/Config/gp247-config.php', 'gp247-config');
        $this->mergeConfigFrom(__DIR__.'/Config/gp247-module.php', 'gp247-module');

        $this->mergeConfigFrom(__DIR__.'/Config/disks_gp247.php', 'filesystems.disks');
        $this->mergeConfigFrom(__DIR__.'/Config/auth_guards_gp247.php', 'auth.guards');
        $this->mergeConfigFrom(__DIR__.'/Config/auth_passwords_gp247.php', 'auth.passwords');
        $this->mergeConfigFrom(__DIR__.'/Config/auth_providers_gp247.php', 'auth.providers');
        $this->mergeConfigFrom(__DIR__.'/Config/lfm.php', 'lfm');

        if (file_exists(__DIR__.'/Library/Const.php')) {
            require_once(__DIR__.'/Library/Const.php');
        }

    }

    public function bootDefault()
    {
        // Set store id
        // Default is domain root
        $storeId = GP247_STORE_ID_ROOT;

        //Process for multi store
        if (gp247_store_check_multi_partner_installed() ||  gp247_store_check_multi_store_installed()) {
            $domain = gp247_store_process_domain(url('/'));
            $arrDomain = AdminStore::getDomainStore();
            if (in_array($domain, $arrDomain)) {
                $storeId =  array_search($domain, $arrDomain);
            }
        }
        //End process multi store
        
        config(['app.storeId' => $storeId]);
        // end set store Id
        
        //Config for logging
        if (gp247_config_global('LOG_SLACK_WEBHOOK_URL')) {
            config(['logging.channels.slack.url' => gp247_config_global('LOG_SLACK_WEBHOOK_URL')]);
        }
        config(['logging.default' => 'daily']);
        config(['logging.channels.daily.path' => storage_path('logs/gp247.log')]);
        config(['logging.channels.daily.permission' => 0664]);

        //Title app
        config(['app.name' => gp247_store_info('title')]);

        //Config for  email
        if (
            // Default use smtp mode for for supplier if use multi-store
            ($storeId != GP247_STORE_ID_ROOT && (gp247_store_check_multi_partner_installed() ||  gp247_store_check_multi_store_installed()))
            ||
            // Use smtp config from admin if root domain have smtp_mode enable
            ($storeId == GP247_STORE_ID_ROOT && gp247_config_global('smtp_mode'))
        ) {
            $smtpHost     = gp247_config('smtp_host');
            $smtpPort     = (int)gp247_config('smtp_port') ?: config('mail.mailers.smtp.port'); // smtp port must be int value
            $smtpSecurity = gp247_config('smtp_security');
            $smtpUser     = gp247_config('smtp_user');
            $smtpPassword = gp247_config('smtp_password');
            $smtpName     = gp247_config('smtp_name');
            $smtpFrom     = gp247_config('smtp_from');
            config(['mail.default'                 => 'smtp']);
            config(['mail.mailers.smtp.host'       => $smtpHost]);
            config(['mail.mailers.smtp.port'       => $smtpPort]);
            config(['mail.mailers.smtp.encryption' => $smtpSecurity]);
            config(['mail.mailers.smtp.username'   => $smtpUser]);
            config(['mail.mailers.smtp.password'   => $smtpPassword]);
            config(['mail.from.address'            => ($smtpFrom ?? gp247_store_info('email'))]);
            config(['mail.from.name'               => ($smtpName ?? gp247_store_info('title'))]);
        } else {
            //Set default
            config(['mail.from.address' => (config('mail.from.address')) ? config('mail.from.address') : gp247_store_info('email')]);
            config(['mail.from.name'    => (config('mail.from.name')) ? config('mail.from.name') : gp247_store_info('title')]);
        }
        //email

        //Share variable for view
        view()->share('gp247_languages', gp247_language_all());
    }

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'localization'     => Localization::class,
        'api.connection'   => ApiConnection::class,
        'json.response'    => ForceJsonResponse::class,
        //Admin
        'admin.auth'       => Authenticate::class,
        'admin.log'        => LogOperation::class,
        'admin.permission' => PermissionMiddleware::class,
        'admin.storeId'    => AdminStoreId::class,
        'admin.pjax'      => FilterIfPjax::class,
        'admin.session'    => Session::class,
        //Sanctum
        'abilities'        => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability'          => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected function middlewareGroups()
    {
        return [
            'admin'        => config('gp247-config.admin.middleware'),
            'api.extend'   => config('gp247-config.api.middleware'),
        ];
    }

    /**
     * Register the route middleware.
     *
     * @return void
     */
    protected function registerRouteMiddleware()
    {
        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }

        // register middleware group.
        foreach ($this->middlewareGroups() as $key => $middleware) {
            app('router')->middlewareGroup($key, array_values($middleware));
        }
    }

    /**
     * Validattion extend
     *
     * @return  [type]  [return description]
     */
    protected function validationExtend()
    {
        //
    }

    /**
     * Register the package's publishable resources.
     *
     * @return void
     */
    protected function registerPublishing()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/public/GP247' => public_path('GP247')], 'gp247:public-static');
            $this->publishes([__DIR__.'/public/vendor' => public_path('vendor')], 'gp247:public-vendor');
            $this->publishes([__DIR__.'/Views/admin' => resource_path('views/vendor/gp247-core')], 'gp247:view-core');
            $this->publishes([__DIR__.'/Config/lfm.php' => config_path('lfm.php')], 'gp247:config-lfm');
            $this->publishes([__DIR__.'/Config/gp247_functions_except.stub' => config_path('gp247_functions_except.php')], 'gp247:functions-except');
        }
    }

    //Event register
    protected function eventRegister()
    {
        //
    }
}
