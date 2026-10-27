<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Api\Support\ApiSwitch;
use Illuminate\Http\Request;

/**
 * Gate at the head of the shared API middleware (`api.enabled`): a request to a group
 * the site owner turned off on the API management screen answers 404 `api_disabled`
 * before the API Connection check and the rate limit. Read on every request, so it
 * works at once even with cached routes.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-admin-switch
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // WHY the matched route's uri: grouping must follow what the router matched, not the raw path.
        $uri = $request->route()?->uri() ?? $request->path();
        if (!ApiSwitch::allows($uri)) {
            return ApiErrorResponder::respond(404, 'api_disabled', 'This API is disabled on this site');
        }

        return $next($request);
    }
}
