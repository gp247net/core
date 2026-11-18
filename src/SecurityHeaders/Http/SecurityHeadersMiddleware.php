<?php

namespace GP247\Core\SecurityHeaders\Http;

use Closure;
use GP247\Core\SecurityHeaders\Application\HeaderPolicyResolver;
use GP247\Core\SecurityHeaders\Domain\ResponseKind;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the security headers a response is still missing. Prepended to the `web` and `api`
 * groups, so on the way out it runs last and sees every header the controller, a plugin
 * or another middleware already set — those are never overwritten nor duplicated.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-defaults, US-security-headers-respect-server, US-security-headers-emergency-off, US-security-headers-hsts-opt-in
 * @aidlc-adr security-headers_response-middleware
 */
class SecurityHeadersMiddleware
{
    /**
     * @param HeaderPolicyResolver $resolver
     */
    public function __construct(private readonly HeaderPolicyResolver $resolver)
    {
    }

    /**
     * Let the request through, then add the missing headers to the response.
     *
     * @param Request $request
     * @param Closure  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $policy = $this->resolver->current();
        } catch (\Throwable $e) {
            // WHY: headers are a second line of defence; a failure to read the settings must not
            // turn every page into an error — the page goes out as it would without this layer.
            report($e);
            return $response;
        }
        if ($policy === null) {
            return $response;
        }
        foreach ($policy->headersFor(ResponseKind::of($response), $request->isSecure()) as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
