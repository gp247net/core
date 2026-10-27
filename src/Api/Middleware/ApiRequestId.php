<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Head of the shared API middleware (`api.request_id`): every GP247 API response
 * carries an X-Request-Id, including error answers and `api_disabled`, so a client
 * can quote it and the site owner can find the matching log lines. A well-formed id
 * sent by the client is reused (the app may already log it); anything else is
 * replaced by a fresh UUID — never echoed raw, which keeps header injection out.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-error-contract
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiRequestId
{
    public const HEADER = 'X-Request-Id';

    /** Request attribute holding the id for the rest of the request (gp247_report_context). */
    public const ATTRIBUTE = 'gp247_request_id';

    /** Accepted client ids: url-safe, 8–128 characters (\z: a trailing newline must not pass). */
    private const PATTERN = '/^[A-Za-z0-9._-]{8,128}\z/';

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $sent = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match(self::PATTERN, $sent) ? $sent : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
