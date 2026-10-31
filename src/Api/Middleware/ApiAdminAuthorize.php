<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use GP247\Core\AdminShell\Application\AdminRouteAccess;
use GP247\Core\Api\Support\ApiAdminLog;
use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Api\Support\ApiStoreScope;
use GP247\Core\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Authorize an admin REST API call like the admin screen it maps to.
 *
 * Usage on a route: `api.admin:<admin route name>` (after auth:admin-api). Steps:
 * active token owner → bind it to the "admin" guard for this request → RBAC of the
 * mapped admin route → optional tenant fence → resolve the store the call works in.
 * Every failure denies (fail-closed): no mapping, unknown admin route, fence error,
 * resolver error.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-RBAC-api-route-permission, US-CMP-api-admin-store-scope, US-CMP-api-admin-access-log
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiAdminAuthorize
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string  $adminRouteName Admin route whose permission opens this endpoint (`*events`: any event type's screen).
     * @param string  $mode           `read` (default) or `write` (checked like a write action on the screen).
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $adminRouteName = '', string $mode = 'read')
    {
        $user = Auth::guard('admin-api')->user();
        if (!$user instanceof AdminUser || (int) $user->status !== 1) {
            return ApiErrorResponder::unauthenticated();
        }
        // WHY before the RBAC decision: refused attempts are part of the audit trail.
        ApiAdminLog::record($request, $user);

        // WHY: the RBAC, store-resolver and plugin helpers read admin()->user(); setUser()
        // only sets it in memory for this request, it does not start or write a session.
        Auth::guard('admin')->setUser($user);

        // WHY `*events`: the event log has no screen of its own — an admin may read it when
        // allowed to open the screen of at least one registered event type (US-api-sync-event-read).
        $adminRoute = $adminRouteName === '*events'
            ? \GP247\Core\Api\Sync\EventType::firstReadableRoute($user)
            : ($adminRouteName !== '' ? RouteFacade::getRoutes()->getByName($adminRouteName) : null);
        // WHY: HEAD is answered by GET routes; any other verb is checked as itself so a
        // future write endpoint needs the matching write grant (view.all stays read-only).
        $method = in_array($request->method(), ['GET', 'HEAD'], true) ? 'GET' : $request->method();
        if (!$adminRoute instanceof Route) {
            return ApiErrorResponder::forbidden();
        }
        $allowed = $mode === 'write'
            ? $this->allowsWrite($user, $adminRoute)
            : AdminRouteAccess::allows($user, $method, $adminRoute, $this->samplePath($adminRoute));
        if (!$allowed) {
            return ApiErrorResponder::forbidden();
        }

        if (!$this->passesFence($user, $request, $adminRoute)) {
            return ApiErrorResponder::forbidden();
        }

        $storeId = $this->resolveStore($user);
        if ($storeId === null) {
            return ApiErrorResponder::forbidden();
        }
        ApiStoreScope::set($storeId);

        return $next($request);
    }

    /**
     * `api.admin:<screen route>,write`: decide exactly as a write action on that admin
     * screen does (Livewire authorizeAction) — action fence veto, view.all read-only,
     * then a POST grant on the screen path, wildcards included — so whoever may change
     * the data on the screen may change it through the API, and nobody else.
     *
     * @param AdminUser $user
     * @param Route     $screenRoute Admin route of the screen (e.g. admin_order.index).
     * @return bool
     *
     * @aidlc-unit api-admin-write
     * @aidlc-story US-api-admin-write-order-status
     * @aidlc-adr api-admin-write_write-authorization-as-admin-action
     */
    private function allowsWrite(AdminUser $user, Route $screenRoute): bool
    {
        try {
            $path = parse_url(route($screenRoute->getName()), PHP_URL_PATH);
        } catch (\Throwable $e) {
            return false;
        }
        $screenUri = is_string($path) ? ltrim($path, '/') : null;

        return app(\GP247\Core\AdminShell\Application\AuthorizeAdminAction::class)
            ->authorize(new \GP247\Core\AdminShell\Infrastructure\EloquentAdminUserAdapter($user), $screenUri, 'update')->isAllowed();
    }

    /**
     * Run the tenant fence registered by a plugin (gp247-config.api.admin_fence).
     *
     * A store resolver without a fence means the plugin binds store-admins through a
     * web-only mechanism the API cannot see; then only administrators may pass.
     *
     * @param AdminUser $user
     * @param Request   $request
     * @param Route     $adminRoute Admin route the endpoint maps to (third fence argument).
     * @return bool
     */
    private function passesFence(AdminUser $user, Request $request, Route $adminRoute): bool
    {
        $fence = config('gp247-config.api.admin_fence');
        if (!is_callable($fence)) {
            return !is_callable(config('gp247-config.admin.store_resolver')) || $user->isAdministrator();
        }
        try {
            return call_user_func($fence, $user, $request, $adminRoute) === true;
        } catch (\Throwable $e) {
            gp247_report($e);
            return false;
        }
    }

    /**
     * Store of this call from the admin store resolver; null when the resolver fails.
     *
     * WHY: gp247_admin_store_resolve() falls back to ROOT on error, which on the API
     * would widen a store-admin to every store; here an error denies instead.
     *
     * @param AdminUser $user
     * @return string|null
     */
    private function resolveStore(AdminUser $user): ?string
    {
        $resolver = config('gp247-config.admin.store_resolver');
        if (!is_callable($resolver)) {
            return (string) GP247_STORE_ID_ROOT;
        }
        try {
            $storeId = call_user_func($resolver);
        } catch (\Throwable $e) {
            gp247_report($e);
            return null;
        }
        if ($storeId === null || $storeId === '' || $storeId === false) {
            // WHY: an empty answer must not widen a confined admin to every store.
            return $user->isAdministrator() ? (string) GP247_STORE_ID_ROOT : null;
        }
        return (string) $storeId;
    }

    /**
     * Concrete path of $route with placeholder values for its parameters, so path
     * grants such as "ANY::gp247_admin/shop_order/*" match like on the web.
     *
     * @param Route $route
     * @return string
     */
    private function samplePath(Route $route): string
    {
        $path = $route->uri();
        foreach ($route->parameterNames() as $name) {
            $path = preg_replace('/\{' . preg_quote($name, '/') . '\??\}/', '0', $path);
        }
        return trim(preg_replace('#/+#', '/', $path), '/');
    }
}
