<?php

namespace GP247\Core\Api\Middleware;

use Closure;
use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Api\Sync\IdempotencyGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.idempotent` (key required) / `api.idempotent:optional` on an admin API write:
 * a retry with the same Idempotency-Key and body answers the stored result instead of
 * running again (US-api-sync-write-idempotency). Error codes match the checkout key.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-write-idempotency
 * @aidlc-adr api-sync_admin-idempotency-store
 */
class ApiIdempotent
{
    /**
     * @param Request $request
     * @param Closure $next
     * @param string  $mode `required` (default) or `optional`.
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        $key = $request->header(IdempotencyGuard::HEADER);
        if ($key === null || $key === '') {
            return $mode === 'optional' ? $next($request) : $this->keyRequired();
        }
        if (!IdempotencyGuard::isWellFormed($key)) {
            return $this->keyRequired();
        }

        if (!IdempotencyGuard::ready()) {
            // WHY fail closed: running a non-repeatable write without the key store could apply it twice.
            return ApiErrorResponder::respond(503, 'idempotency_unavailable', 'The site must run php artisan gp247:update before this write can be used');
        }
        $guard = app(IdempotencyGuard::class);
        [$decision, $row] = $guard->claim(
            IdempotencyGuard::scopeFor($request->user(), $request),
            $key,
            IdempotencyGuard::fingerprint($request->method(), $request->path(), $request->all())
        );
        if ($decision === IdempotencyGuard::REPLAY) {
            return response((string) $row->response_body, (int) $row->response_status, [
                'Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true',
            ]);
        }
        if ($decision === IdempotencyGuard::REUSED) {
            return ApiErrorResponder::respond(409, 'idempotency_key_reused', 'This Idempotency-Key was already used for another request');
        }
        if ($decision === IdempotencyGuard::IN_PROGRESS) {
            return ApiErrorResponder::respond(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $guard->release((int) $row->id);
            throw $e;
        }
        $guard->complete((int) $row->id, $response);

        return $response;
    }

    /**
     * @return Response
     */
    private function keyRequired(): Response
    {
        return ApiErrorResponder::respond(400, 'idempotency_key_required', 'Send an Idempotency-Key header of 8 to 255 printable characters');
    }
}
