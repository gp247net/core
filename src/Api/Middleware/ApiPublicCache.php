<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Opt-in public caching for a route that never carries one person's data
 * (`api.cache.public` — the public catalog and content). Without a token a successful
 * answer may be kept by browsers and a CDN for `api.cache.public_max_age` seconds
 * (default 0: always revalidate, 304 when unchanged), varied on Authorization; with a
 * token — or while the site requires an API Connection key — it is private and not
 * stored. Caching is never inferred from "no token": a guest cart is read without one,
 * so only routes that declare this middleware qualify.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-public-cache-control
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiPublicCache
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

        // WHY the API Connection gate too: with it on, a copy kept by a CDN would be served to
        // apps that hold no key, bypassing the gate.
        if ($request->headers->has('Authorization') || gp247_config('api_connection_required')) {
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        }
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        // WHY read config here, not a route parameter: route:cache would freeze the value.
        $maxAge = max(0, (int) config('gp247-config.api.cache.public_max_age', 0));
        $response->headers->set('Cache-Control', 'public, max-age=' . $maxAge);
        $response->setVary('Authorization', false);

        return $response;
    }
}
