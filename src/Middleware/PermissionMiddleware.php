<?php

namespace GP247\Core\Middleware;

use GP247\Core\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @param array                    $args
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, ...$args)
    {
        if (!empty($args) || (admin()->user() && admin()->user()->isAdministrator())) {
            return $next($request);
        }

        // Administrator-only screens: refused before View.all and role grants are considered.
        if (in_array($request->path(), $this->administratorOnly(), true)) {
            if (!request()->ajax()) {
                return redirect(gp247_route_admin('admin.deny'))->with(['url' => $request->url(), 'method' => $request->method()]);
            }
            return Permission::error();
        }

        //Group view all
        // this group can view all path, but cannot change data
        // This condition need add before check permissions below
        if (admin()->user() && admin()->user()->isViewAll()) {
            if ($request->method() == 'GET'
                && !collect($this->viewWithoutToMessage())->contains($request->path())
                && !collect($this->viewWithout())->contains($request->path())
            ) {
                return $next($request);
            } else {
                if (!request()->ajax()) {
                    if (collect($this->viewWithoutToMessage())->contains($request->path())) {
                        return redirect(gp247_route_admin('admin.deny_single'))->with(['url' => $request->url(), 'method' => $request->method()]);
                    }
                    return redirect(gp247_route_admin('admin.deny'))->with(['url' => $request->url(), 'method' => $request->method()]);
                } else {
                    if (collect($this->viewWithoutToMessage())->contains($request->path())) {
                        return redirect(gp247_route_admin('admin.deny_single'))->with(['url' => $request->url(), 'method' => $request->method()]);
                    }
                    return Permission::error();
                }
            }
        }


        // Allow access route
        if ($this->routeDefaultPass($request)) {
            return $next($request);
        }

        // Allow access path
        if ($this->pathDefaultPass($request)) {
            return $next($request);
        }

        // Allow notice
        if (Str::startsWith($request->route()->getName(), 'admin_notice.')) {
            return $next($request);
        }

        if (admin()->user() && !admin()->user()->allPermissions()->first(function ($modelPermission) use ($request) {
            return $modelPermission->passRequest($request);
        })) {
            if (!request()->ajax()) {
                if (request()->route()->getName() == 'admin.home') {
                    return redirect(gp247_route_admin('admin.default'))->with(['title' => gp247_language_render('admin.home')]);
                }
                return redirect(gp247_route_admin('admin.deny'))->with(['url' => $request->url(), 'method' => $request->method()]);
            } else {
                return Permission::error();
            }
        }
        return $next($request);
    }

    /**
     * Determine if the request has a URI that should pass through verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function pathDefaultPass($request)
    {
        $routePath = $request->path();
        $exceptsPAth = Permission::listPathDefaultPassThrough();
        return in_array($routePath, $exceptsPAth);
    }

    /*
    Check route defualt allow access
    */
    public function routeDefaultPass($request)
    {
        $routeName = $request->route()->getName();
        $allowRoute = Permission::listRouteDefaultPassThrough();
        return in_array($routeName, $allowRoute);
    }

    public function viewWithout()
    {
        return [
            // Array item in here
        ];
    }

    /**
     * Send page deny as meeasge
     *
     * @return  [type]  [return description]
     */
    public function viewWithoutToMessage()
    {
        return [
            GP247_ADMIN_PREFIX . '/uploads/delete',
            GP247_ADMIN_PREFIX . '/uploads/newfolder',
            GP247_ADMIN_PREFIX . '/uploads/domove',
            GP247_ADMIN_PREFIX . '/uploads/rename',
            GP247_ADMIN_PREFIX . '/uploads/resize',
            GP247_ADMIN_PREFIX . '/uploads/doresize',
            GP247_ADMIN_PREFIX . '/uploads/cropimage',
            GP247_ADMIN_PREFIX . '/uploads/crop',
            GP247_ADMIN_PREFIX . '/uploads/move',
        ];
    }

    /**
     * Admin paths only the Administrator role may open.
     *
     * WHY: these screens list the site's weak points. The rule wins over every other
     * grant — View.all, default pass-through and role permissions — so it is checked
     * right after the Administrator shortcut here, in AdminRouteAccess::allows() and
     * in AdminUser::checkUrlAllowAccess() (menu).
     *
     * @return array<int, string> Request paths without leading slash, e.g. "gp247_admin/system_alerts".
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts-page, US-admin-shell-login-failure-list
     * @aidlc-adr admin-shell_system-alerts-page, admin-shell_login-failure-log
     */
    public function administratorOnly(): array
    {
        return [
            GP247_ADMIN_PREFIX . '/system_alerts',
            GP247_ADMIN_PREFIX . '/login_failures',
        ];
    }
}
