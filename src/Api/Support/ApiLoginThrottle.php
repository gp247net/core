<?php

namespace GP247\Core\Api\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Login attempt limits for the REST API, two counters per attempt:
 *  - per account: keyed by the resolved account id (falls back to the normalized
 *    identity when no account matches), so spelling variants the database collation
 *    treats as equal still share one counter;
 *  - per client IP: caps password spraying across many accounts.
 * The attempt is counted BEFORE the password check (no burst past the limit with
 * concurrent requests); a successful login gives the attempt back. Uses the default
 * cache store, so it works on shared hosting without Redis or cron.
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
        [$perAccount, $decayMinutes] = self::limits('login', '5,1');
        [$perIp, $ipDecayMinutes] = self::limits('login_ip', '20,1');
        $accountKey = self::accountKey($audience, $subject, $request);
        $ipKey = self::ipKey($audience, $request);

        foreach ([[$accountKey, $perAccount], [$ipKey, $perIp]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = RateLimiter::availableIn($key);
                return ApiErrorResponder::respond(
                    429,
                    'too_many_attempts',
                    'Too many login attempts. Please try again in ' . $retryAfter . ' seconds.',
                    ['Retry-After' => $retryAfter]
                );
            }
        }

        RateLimiter::hit($accountKey, $decayMinutes * 60);
        RateLimiter::hit($ipKey, $ipDecayMinutes * 60);

        return null;
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
        RateLimiter::clear(self::accountKey($audience, $subject, $request));
        [, $ipDecayMinutes] = self::limits('login_ip', '20,1');
        $ipKey = self::ipKey($audience, $request);
        if (RateLimiter::attempts($ipKey) > 0) {
            RateLimiter::decrement($ipKey, $ipDecayMinutes * 60);
        }
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
        return $accountId !== null && $accountId !== ''
            ? 'id:' . $accountId
            : 'name:' . Str::lower(trim($identity));
    }

    /**
     * [max attempts, decay minutes] from gp247-config.api.throttle.<key> ("5,1").
     *
     * @param string $key
     * @param string $default
     * @return int[]
     */
    private static function limits(string $key, string $default): array
    {
        $parts = explode(',', (string) config('gp247-config.api.throttle.' . $key, $default));

        return [max(1, (int) ($parts[0] ?? 5)), max(1, (int) ($parts[1] ?? 1))];
    }

    /**
     * @param string  $audience
     * @param string  $subject
     * @param Request $request
     * @return string
     */
    private static function accountKey(string $audience, string $subject, Request $request): string
    {
        return 'gp247-api-login|' . $audience . '|' . sha1($subject . '|' . $request->ip());
    }

    /**
     * @param string  $audience
     * @param Request $request
     * @return string
     */
    private static function ipKey(string $audience, Request $request): string
    {
        return 'gp247-api-login-ip|' . $audience . '|' . sha1((string) $request->ip());
    }
}
