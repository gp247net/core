<?php

namespace GP247\Core\Api\Support;

use GP247\Core\Support\LoginThrottle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST API face of the shared login limiter (GP247\Core\Support\LoginThrottle):
 * same counters, answered as the API error contract (429 + Retry-After).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-login-throttle
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiLoginThrottle
{
    /**
     * Count one login attempt, or refuse it when a limit is already reached.
     *
     * @param Request $request
     * @param string  $audience "admin" or "customer" (separate counters).
     * @param string  $subject  Account id when known, else the typed identity.
     * @return JsonResponse|null 429 response when blocked, null when the attempt may proceed.
     */
    public static function attempt(Request $request, string $audience, string $subject): ?JsonResponse
    {
        $retryAfter = LoginThrottle::hit($request, $audience, $subject);
        if ($retryAfter === null) {
            return null;
        }

        return ApiErrorResponder::respond(
            429,
            'too_many_attempts',
            'Too many login attempts. Please try again in ' . $retryAfter . ' seconds.',
            ['Retry-After' => $retryAfter]
        );
    }

    /**
     * A successful login: reset the account counter and give the IP its attempt back.
     *
     * @param Request $request
     * @param string  $audience
     * @param string  $subject Same subject as passed to attempt().
     * @return void
     */
    public static function succeeded(Request $request, string $audience, string $subject): void
    {
        LoginThrottle::succeeded($request, $audience, $subject);
    }

    /**
     * Subject of a login: "id:<account id>" when $accountId is known, else the
     * normalized identity.
     *
     * @param mixed  $accountId Id of the account the identity resolves to, or null.
     * @param string $identity  Typed username/email.
     * @return string
     */
    public static function subject($accountId, string $identity): string
    {
        return LoginThrottle::subject($accountId, $identity);
    }
}
