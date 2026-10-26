<?php

namespace GP247\Core\Api\Support;

use Illuminate\Http\JsonResponse;

/**
 * Single shape for every REST API error: {"error":1,"msg":"…","code":"…"}.
 * The "error"/"msg" keys are kept from the v1 payloads so existing clients keep
 * working; "code" is the machine-readable reason.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-error-contract
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiErrorResponder
{
    /**
     * Build an error response.
     *
     * @param int    $status  HTTP status.
     * @param string $code    Machine-readable code, e.g. "forbidden".
     * @param string $msg     Human-readable message (no internal details).
     * @param array  $headers Extra response headers.
     * @return JsonResponse
     */
    public static function respond(int $status, string $code, string $msg, array $headers = []): JsonResponse
    {
        return response()->json(['error' => 1, 'msg' => $msg, 'code' => $code], $status, $headers);
    }

    /**
     * 401 for a missing, expired or no longer valid credential.
     *
     * @param string $msg
     * @return JsonResponse
     */
    public static function unauthenticated(string $msg = 'Unauthenticated'): JsonResponse
    {
        return self::respond(401, 'unauthenticated', $msg);
    }

    /**
     * 403 for an authenticated caller without the right to the resource.
     *
     * @param string $msg
     * @return JsonResponse
     */
    public static function forbidden(string $msg = 'Forbidden'): JsonResponse
    {
        return self::respond(403, 'forbidden', $msg);
    }

    /**
     * 404 for a record that does not exist or is not visible to the caller.
     *
     * @param string $msg
     * @return JsonResponse
     */
    public static function notFound(string $msg = 'Not found'): JsonResponse
    {
        return self::respond(404, 'not_found', $msg);
    }

    /**
     * Render any exception thrown while serving an `api/*` request; null for other
     * requests so the application's own handling applies.
     *
     * @param \Throwable               $e
     * @param \Illuminate\Http\Request $request
     * @return JsonResponse|null
     */
    public static function fromException(\Throwable $e, $request): ?JsonResponse
    {
        if (!self::isGp247Api($request)) {
            return null;
        }
        // WHY: these already carry the response their author built (abort(response()),
        // FormRequest::failedValidation overrides); rewriting them would lose it.
        if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException
            || $e instanceof \Illuminate\Contracts\Support\Responsable
            || ($e instanceof \Illuminate\Validation\ValidationException && $e->response !== null)) {
            return null;
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return response()->json([
                'error'  => 1,
                'msg'    => $e->getMessage(),
                'code'   => 'validation_failed',
                'errors' => $e->errors(),
            ], 422);
        }
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return self::unauthenticated();
        }
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException
            || $e instanceof \GP247\Core\AdminShell\Domain\AuthorizationException
            || $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            return self::forbidden();
        }
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
            || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return self::notFound();
        }
        if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
            return self::respond(429, 'too_many_attempts', 'Too many requests', $e->getHeaders());
        }
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
            return self::respond(405, 'method_not_allowed', 'Method not allowed', $e->getHeaders());
        }
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $codes = [401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 429 => 'too_many_attempts'];
            $status = $e->getStatusCode();
            return self::respond($status, $codes[$status] ?? 'http_error', $e->getMessage() ?: 'Request failed', $e->getHeaders());
        }

        // WHY: the exception is still reported by the handler; the body never carries
        // internals unless debug mode is on.
        return self::respond(500, 'server_error', config('app.debug') ? $e->getMessage() : 'Server error');
    }

    /**
     * Whether $request targets the GP247 REST API (its own prefixes only — the host
     * application's routes/api.php and third-party api/* routes keep their format).
     *
     * @param \Illuminate\Http\Request $request
     * @return bool
     */
    public static function isGp247Api($request): bool
    {
        $patterns = [];
        foreach (['GP247_API_CORE_PREFIX', 'GP247_API_FRONT_PREFIX', 'GP247_API_V2_CORE_PREFIX', 'GP247_API_V2_FRONT_PREFIX'] as $constant) {
            if (defined($constant)) {
                $prefix = trim(constant($constant), '/');
                $patterns[] = $prefix;
                $patterns[] = $prefix . '/*';
            }
        }

        return $patterns !== [] && $request->is(...$patterns);
    }
}
