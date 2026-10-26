<?php

namespace GP247\Core\AdminShell\Application;

use GP247\Core\Middleware\PermissionMiddleware;
use GP247\Core\Models\AdminUser;
use GP247\Core\Permission;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Decide whether an admin may open an admin route, outside of that route's own
 * request. It applies the same rules, in the same order and from the same lists, as
 * PermissionMiddleware (administrator, view.all read-only, default pass-through
 * routes/paths, notices, then the admin's http_uri grants) so a caller such as the
 * admin REST API can never grant more — or less — than the web screen it maps to.
 *
 * @aidlc-unit rbac
 * @aidlc-story US-RBAC-api-route-permission
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class AdminRouteAccess
{
    /**
     * Whether $user may call $route with $method on $path.
     *
     * @param AdminUser $user   The admin being authorized (not necessarily the session admin).
     * @param string    $method HTTP method of the admin screen request, e.g. "GET".
     * @param Route     $route  The admin route being opened.
     * @param string    $path   Concrete path of that route without leading slash.
     * @return bool
     */
    public static function allows(AdminUser $user, string $method, Route $route, string $path): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        if ($user->isViewAll()) {
            $guard = new PermissionMiddleware();
            return $method === 'GET'
                && !in_array($path, $guard->viewWithoutToMessage(), true)
                && !in_array($path, $guard->viewWithout(), true);
        }

        if (in_array($route->getName(), Permission::listRouteDefaultPassThrough(), true)
            || in_array($path, Permission::listPathDefaultPassThrough(), true)
            || Str::startsWith((string) $route->getName(), 'admin_notice.')) {
            return true;
        }

        return self::permissionsOf($user)->contains(
            fn ($permission) => $permission->passesFor($method, $route->uri(), $path)
        );
    }

    /**
     * Role permissions plus direct permissions of $user.
     *
     * WHY: AdminUser::allPermissions() is memoised for the session admin only; the
     * API authorizes the token owner, so the set is loaded from the user itself.
     *
     * @param AdminUser $user
     * @return \Illuminate\Support\Collection
     */
    private static function permissionsOf(AdminUser $user)
    {
        return $user->roles()->with('permissions')->get()
            ->pluck('permissions')->flatten()
            ->merge($user->permissions);
    }
}
