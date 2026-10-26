<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use GP247\Core\Api\Support\ApiErrorResponder;
use Illuminate\Http\Request;

/**
 * Optional application gate in front of the whole REST API: when the site enables
 * api_connection_required, every call must carry a valid header pair
 * `apiconnection` + `apikey` (key compared by hash); otherwise 401.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-connection-key-hashing
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiConnection
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
        if (!gp247_config('api_connection_required')) {
            return $next($request);
        }
        $apiconnection = (string) $request->header('apiconnection');
        $apikey = (string) $request->header('apikey');
        if ($apiconnection === '' || $apikey === '') {
            return ApiErrorResponder::respond(401, 'api_connection_invalid', 'apiconnection or apikey not found');
        }
        $connection = \GP247\Core\Models\AdminApiConnection::check($apiconnection, $apikey);
        if (!$connection) {
            return ApiErrorResponder::respond(401, 'api_connection_invalid', 'Connection not correct');
        }
        $connection->touchActivity();

        return $next($request);
    }
}
