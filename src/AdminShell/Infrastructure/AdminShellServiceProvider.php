<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Application\PermissionResolver;
use GP247\Core\AdminShell\Domain\AdminActionAuthorizer;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Http\Livewire\AdminLogList;
use GP247\Core\AdminShell\Http\Livewire\HomeLayoutForm;
use GP247\Core\AdminShell\Http\Livewire\HomeLayoutList;
use GP247\Core\AdminShell\Http\Livewire\ServerInfo;
use GP247\Core\AdminShell\Http\Livewire\LanguageManager;
use GP247\Core\AdminShell\Http\Livewire\LanguageStringManager;
use GP247\Core\AdminShell\Http\Livewire\PermissionForm;
use GP247\Core\AdminShell\Http\Livewire\PermissionList;
use GP247\Core\AdminShell\Http\Livewire\PermissionManager;
use GP247\Core\AdminShell\Http\Livewire\RoleForm;
use GP247\Core\AdminShell\Http\Livewire\RoleList;
use GP247\Core\AdminShell\Http\Livewire\RoleManager;
use GP247\Core\AdminShell\Http\Livewire\CustomFieldForm;
use GP247\Core\AdminShell\Http\Livewire\CustomFieldList;
use GP247\Core\AdminShell\Http\Livewire\EmailSettingsForm;
use GP247\Core\AdminShell\Http\Livewire\GeneralSettingsForm;
use GP247\Core\AdminShell\Http\Livewire\GlobalConfigForm;
use GP247\Core\AdminShell\Http\Livewire\CacheConfigForm;
use GP247\Core\AdminShell\Http\Livewire\ApiConnectionManager;
use GP247\Core\AdminShell\Http\Livewire\CustomConfigForm;
use GP247\Core\AdminShell\Http\Livewire\SettingsHub;
use GP247\Core\AdminShell\Http\Livewire\WebsiteInfo;
use GP247\Core\AdminShell\Http\Livewire\MenuManager;
use GP247\Core\AdminShell\Http\Livewire\NoticeList;
use GP247\Core\AdminShell\Http\Livewire\PasswordPolicyForm;
use GP247\Core\AdminShell\Http\Livewire\UserManager;
use GP247\Core\Models\AdminUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Wires the admin-shell authorization layer into the application container and
 * the Livewire runtime (ADR-001 / ADR-003).
 *
 * All registrations are additive: they introduce new bindings, a Livewire
 * component namespace and a middleware alias without touching the existing
 * GP247 route or middleware stack.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-LW-001
 * @aidlc-adr ADR-001
 */
final class AdminShellServiceProvider extends ServiceProvider
{
    /**
     * Register container bindings for the authorization core.
     *
     * @return void
     */
    public function register(): void
    {
        // Per-request adapter over the brownfield AdminUser; swappable in tests.
        $this->app->bind(AdminUserContract::class, static function (): AdminUserContract {
            /** @var AdminUser $user */
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
    }

    /**
     * Register the Livewire component namespace and the Layer-1 guard alias.
     *
     * @return void
     */
    public function boot(): void
    {
        // ADR-002/005/007: `gp247-admin::` is the sole Blade view namespace for
        // this tree (src/Views/admin). The former `gp247-core::` view alias was
        // removed (modification 20260708T090650, follow-up) — no legacy install
        // depended on it. All internal call sites use `gp247-admin::`.
        $this->loadViewsFrom(__DIR__ . '/../../Views/admin', 'gp247-admin');
        // `<x-gp247::button>` resolves to the `components/button.blade.php` view
        // under the `gp247-admin` namespace registered just above.
        Blade::anonymousComponentNamespace('gp247-admin::components', 'gp247');

        // ADR-004: the pre-built admin bundle ships in the package under
        // src/public/GP247/Core/AdminShell and is published to the app's public
        // path by CoreServiceProvider's existing `gp247:core-public` tag (which
        // copies src/public/GP247 -> public/GP247). No Node/Vite on install; the
        // layout loads it via gp247_file('GP247/Core/AdminShell/...').

        // ADR-003: core registers its own prefix so front/shop/plugins never
        // collide on component names. Class-based components resolve under this.
        Livewire::addNamespace(
            'gp247-core',
            classNamespace: 'GP247\\Core\\AdminShell\\Http\\Livewire',
        );

        // Layer-1 (deploy): persist the existing GP247 admin RBAC middleware so
        // Livewire re-applies it on every "livewire/update" request. Livewire
        // verifies the snapshot checksum before re-running these against the
        // original admin page's route, so the URI-based permission + admin auth
        // are re-enforced and cannot be spoofed. Auto-scoped to admin-originated
        // components: frontend routes don't carry these, so the filter skips
        // them. This reuses brownfield RBAC verbatim (ADR-001, NFR-MAINT-001).
        Livewire::addPersistentMiddleware([
            \GP247\Core\Middleware\Authenticate::class,
            \GP247\Core\Middleware\PermissionMiddleware::class,
        ]);

        // Layer-1 (optional): the payload-inspecting guard remains available as
        // an alias for routes that want an explicit, component+method check. It
        // is NOT attached globally (the shared update endpoint also serves
        // frontend components, which have no admin user). See integration plan.
        $this->app['router']->aliasMiddleware('gp247.livewire-guard', LivewireAuthGuard::class);

        $this->registerAdminRoutes();
        $this->registerAuthorizationExceptionRendering();
    }

    /**
     * Turn a denied AuthorizationException into a friendly response instead of
     * the framework's raw error page (US-UI-008): a JSON payload for Livewire's
     * "livewire/update" AJAX calls (picked up by the `admin.js` request hook,
     * which shows it as a toast) and a branded "access denied" screen for a
     * denial hit on the initial full-page GET (e.g. mount()'s authorizeView()).
     *
     * @return void
     */
    private function registerAuthorizationExceptionRendering(): void
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
     * Register the modern admin full-page Livewire routes inside the existing
     * GP247 admin group (prefix + ['web','admin']), so they inherit admin auth +
     * URI-based RBAC (Layer-1) without touching the vendor route files (ADR-002).
     *
     * @return void
     */
    private function registerAdminRoutes(): void
    {
        // Guard: the GP247 admin constants are defined by the core package; skip
        // gracefully if the shell boots before they are available.
        if (!defined('GP247_ADMIN_PREFIX') || !defined('GP247_ADMIN_MIDDLEWARE')) {
            return;
        }

        // Cutover (PA-1): the dashboard and the guest login/forgot/reset screens no
        // longer register their own admin-shell/* URLs. The legacy entry points now
        // render these modern screens in-place: `admin.home` mounts the Dashboard
        // component (core routes.php) and the legacy auth controllers render the
        // modern `gp247-admin::auth.*` views. Only the genuinely new screens (no
        // legacy URL equivalent) keep an admin-shell/* path here.
        Route::prefix(GP247_ADMIN_PREFIX . '/admin-shell')
            ->middleware(GP247_ADMIN_MIDDLEWARE)
            ->group(static function (): void {
                Route::get('language-strings', LanguageStringManager::class)->name('gp247.admin-shell.language-strings');

                // New config sub-screens (no legacy URL equivalent) keep their path.
                Route::get('config/general', GeneralSettingsForm::class)->name('gp247.admin-shell.config.general');
                Route::get('config/email', EmailSettingsForm::class)->name('gp247.admin-shell.config.email');
                Route::get('config/custom', CustomConfigForm::class)->name('gp247.admin-shell.config.custom');
            });
    }
}
