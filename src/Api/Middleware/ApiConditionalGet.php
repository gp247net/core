<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conditional reads of REST API v2 (`api.etag`, in the shared API middleware): a
 * successful GET/HEAD under a v2 prefix gets a weak ETag hashed from the body it sends,
 * and a matching If-None-Match turns the answer into 304 Not Modified with no body.
 * The hash covers exactly what the caller sees, so a change that leaves updated_at
 * alone (a promotion starting, variant stock, a hidden category) still gives a new
 * ETag — which is why Last-Modified / If-Modified-Since are not used. v1 is untouched.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-conditional-get
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiConditionalGet
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
        $response = $next($request);

        if (!$request->isMethodCacheable() || !$response instanceof JsonResponse || $response->getStatusCode() !== 200 || !self::isV2($request)) {
            return $response;
        }
        $response->setEtag(hash('sha256', (string) $response->getContent()), true);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Whether the request is under one of the v2 prefixes.
     *
     * @param Request $request
     * @return bool
     */
    private static function isV2(Request $request): bool
    {
        foreach ([GP247_API_V2_CORE_PREFIX, GP247_API_V2_FRONT_PREFIX] as $prefix) {
            $prefix = trim($prefix, '/');
            if ($request->is($prefix, $prefix . '/*')) {
                return true;
            }
        }
        return false;
    }
}
