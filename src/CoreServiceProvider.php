<?php

namespace GP247\Core;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

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
use GP247\Core\Models\PersonalAccessToken;
use GP247\Core\Models\AdminStore;
use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Application\PermissionResolver;
use GP247\Core\AdminShell\Domain\AdminActionAuthorizer;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Infrastructure\EloquentAdminUserAdapter;
use GP247\Core\AdminShell\Infrastructure\LivewireAuthGuard;
use GP247\Core\AdminShell\Http\Livewire\LanguageStringManager;
use GP247\Core\AdminShell\Http\Livewire\GeneralSettingsForm;
use GP247\Core\AdminShell\Http\Livewire\EmailSettingsForm;
use GP247\Core\AdminShell\Http\Livewire\CustomConfigForm;

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

        // WHY: the installer route must be reachable before gp247-installed.txt exists
        // so new deployments can run the web wizard without CLI access (US-DEP-001).
        if (file_exists($installRoute = __DIR__.'/Routes/install.php')) {
            $this->loadRoutesFrom($installRoute);
        }

        // WHY: merged from AdminShellServiceProvider (modification 20260708T160000).
        // Must stay outside the gp247-installed.txt gate below because the
        // installer wizard itself (route registered unconditionally above) is a
        // Livewire component rendering `gp247-admin::` views (ADR-002 update).
        $this->loadViewsFrom(__DIR__.'/Views/admin', 'gp247-admin');
        // `<x-gp247::button>` resolves under the `gp247-admin` namespace above.
        Blade::anonymousComponentNamespace('gp247-admin::components', 'gp247');
        // ADR-003: core registers its own prefix so front/shop/plugins never
        // collide on component names. Class-based components resolve under this.
        Livewire::addNamespace(
            'gp247-core',
            classNamespace: 'GP247\\Core\\AdminShell\\Http\\Livewire',
        );

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

            // admin-shell-rbac (ADR-001/002/005, merged 20260708T160000): the admin
            // route group and persistent middleware depend on the `admin`
            // middleware-group just registered by registerRouteMiddleware() above,
            // so they must live inside this installed-gate too (RISK-TECH-022 -
            // registering them unconditionally crashed pre-install requests with a
            // 500 BindingResolutionException on the not-yet-registered group).
            try {
                Livewire::addPersistentMiddleware([
                    Authenticate::class,
                    PermissionMiddleware::class,
                ]);
                $this->app['router']->aliasMiddleware('gp247.livewire-guard', LivewireAuthGuard::class);
                $this->registerAdminRoutes();
                $this->registerAuthorizationExceptionRendering();
            } catch (\Throwable $e) {
                $msg = '#GP247::admin_shell_load:: '.$e->getMessage().' - Line: '.$e->getLine().' - File: '.$e->getFile();
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
        // admin-shell-rbac (ADR-001/002/005, merged 20260708T160000): container
        // bindings for the authorization core. Not gated on gp247-installed.txt -
        // no DB access here, same as Core's other bindings below.
        $this->app->bind(AdminUserContract::class, static function (): AdminUserContract {
            $user = admin()->user();

            return new EloquentAdminUserAdapter($user);
        });

        $this->app->singleton(AuthorizeAdminAction::class, static function ($app): AuthorizeAdminAction {
            $map = (array) config('gp247_admin_shell.permission_map', []);

            return new AuthorizeAdminAction(
                new PermissionResolver($map),
                new AdminActionAuthorizer(),
            );
        });

        // Installer bootstrap: before gp247-installed.txt exists, the DB may not
        // be migrated yet, so a `sessions` table (needed by SESSION_DRIVER=database)
        // won't exist either. Livewire reads/writes the session on every request,
        // including the installer wizard's own Step 1 AJAX round-trip - force the
        // file driver until installation completes so the wizard isn't blocked by
        // the very migration it exists to run. Reverts automatically once
        // InstallerWizard::runInstall() writes the flag file.
        if (!file_exists(storage_path('app/gp247-installed.txt'))) {
            config(['session.driver' => 'file']);
        }

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
        config(['app.name' => gp247_store_info('name')]);

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
            config(['mail.from.name'               => ($smtpName ?? gp247_store_info('name'))]);
        } else {
            //Set default
            config(['mail.from.address' => (config('mail.from.address')) ? config('mail.from.address') : gp247_store_info('email')]);
            config(['mail.from.name'    => (config('mail.from.name')) ? config('mail.from.name') : gp247_store_info('name')]);
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
     * Register the modern admin full-page Livewire routes inside the existing
     * GP247 admin group (prefix + ['web','admin']), so they inherit admin auth +
     * URI-based RBAC (Layer-1) without touching the vendor route files (ADR-002).
     * Merged from AdminShellServiceProvider (modification 20260708T160000).
     *
     * @return void
     */
    protected function registerAdminRoutes()
    {
        if (!defined('GP247_ADMIN_PREFIX') || !defined('GP247_ADMIN_MIDDLEWARE')) {
            return;
        }

        Route::prefix(GP247_ADMIN_PREFIX . '/admin-shell')
            ->middleware(GP247_ADMIN_MIDDLEWARE)
            ->group(static function () {
                Route::get('language-strings', LanguageStringManager::class)->name('gp247.admin-shell.language-strings');

                Route::get('config/general', GeneralSettingsForm::class)->name('gp247.admin-shell.config.general');
                Route::get('config/email', EmailSettingsForm::class)->name('gp247.admin-shell.config.email');
                Route::get('config/custom', CustomConfigForm::class)->name('gp247.admin-shell.config.custom');
            });
    }

    /**
     * Turn a denied AuthorizationException into a friendly response instead of
     * the framework's raw error page (US-UI-008): a JSON payload for Livewire's
     * "livewire/update" AJAX calls and a branded "access denied" screen for a
     * denial hit on the initial full-page GET. Merged from
     * AdminShellServiceProvider (modification 20260708T160000).
     *
     * @return void
     */
    protected function registerAuthorizationExceptionRendering()
    {
        $this->app->make(ExceptionHandler::class)->renderable(
            static function (AuthorizationException $e, Request $request) {
                if ($request->hasHeader('X-Livewire')) {
                    return response()->json([
                        'gp247_admin_denied' => true,
                        'message' => gp247_language_render('admin.core.action_denied'),
                    ], 403);
                }

                return response()->view('gp247-admin::errors.access-denied', [], 403);
            }
        );
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
            $this->publishes([__DIR__.'/public/GP247' => public_path('GP247')], 'gp247:core-public');
            $this->publishes([__DIR__.'/Views/admin' => resource_path('views/vendor/gp247-admin')], 'gp247:core-view');
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
