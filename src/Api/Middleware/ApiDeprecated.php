<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Marks a route as deprecated (`api.deprecated:<deprecated-on>,<sunset-on>,<anchor>`):
 * every answer, error or not, says when it was deprecated (`Deprecation: @<unix time>`,
 * RFC 9745), when it stops working (`Sunset`, RFC 8594 — left out without a date) and
 * where the migration guide is (`Link: <…#anchor>; rel="deprecation"`). Dates are
 * YYYY-MM-DD in UTC. Policy: announce at least 12 months and two minor releases ahead.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-deprecation-policy
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiDeprecated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string       $deprecatedOn Date the route was deprecated (YYYY-MM-DD).
     * @param  string|null  $sunsetOn     Date it stops working (YYYY-MM-DD), optional.
     * @param  string|null  $anchor       Section of the API guide describing the replacement.
     * @return mixed
     */
    public function handle(Request $request, Closure $next, string $deprecatedOn, ?string $sunsetOn = null, ?string $anchor = null)
    {
        $response = $next($request);

        $response->headers->set('Deprecation', '@' . Carbon::parse($deprecatedOn, 'UTC')->startOfDay()->getTimestamp());
        if ($sunsetOn) {
            $response->headers->set('Sunset', Carbon::parse($sunsetOn, 'UTC')->startOfDay()->format('D, d M Y H:i:s') . ' GMT');
        }
        $guide = self::guideUrl();
        if ($guide !== '') {
            $response->headers->set('Link', '<' . $guide . ($anchor ? '#' . $anchor : '') . '>; rel="deprecation"', false);
        }

        return $response;
    }

    /**
     * URL of the public API guide in the current language (English when missing).
     *
     * @return string
     */
    private static function guideUrl(): string
    {
        $guides = (array) config('gp247-config.api.docs.guide', []);

        return (string) ($guides[app()->getLocale()] ?? $guides['en'] ?? '');
    }
}
