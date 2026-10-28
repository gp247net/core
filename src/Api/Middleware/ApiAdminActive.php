<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use GP247\Core\Api\Support\ApiAdminLog;
use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Refuse an admin API token whose owner has been deactivated (status != 1) since the
 * token was issued. Used on the token-owner endpoints (info, logout) that need no
 * screen permission; data endpoints get the same check from ApiAdminAuthorize.
 * Alias `api.admin.active`, after auth:admin-api.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-RBAC-api-route-permission, US-CMP-api-admin-access-log
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiAdminActive
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('admin-api')->user();
        if (!$user instanceof AdminUser || (int) $user->status !== 1) {
            return ApiErrorResponder::unauthenticated();
        }
        ApiAdminLog::record($request, $user);

        return $next($request);
    }
}
