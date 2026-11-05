<?php

namespace GP247\Core\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Login attempt limits shared by every login channel, two counters per attempt:
 *  - per account: keyed by the resolved account id (falls back to the normalized
 *    identity when no account matches), so spelling variants the database collation
 *    treats as equal still share one counter;
 *  - per client IP: caps password spraying across many accounts.
 * The attempt is counted BEFORE the password check (no burst past the limit with
 * concurrent requests); a successful login gives the attempt back. Channels that
 * pass the same audience share one counter (admin web form + admin API). Uses the
 * default cache store, so it works on shared hosting without Redis or cron.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-login-throttle, US-AUI-admin-login-throttle
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class LoginThrottle
{
    /**
     * Count one login attempt, or refuse it when a limit is already reached.
     *
     * @param Request $request
     * @param string  $audience "admin" or "customer" (separate counters).
     * @param string  $subject  From subject(): account id when known, else the typed identity.
     * @return int|null Seconds until the next attempt is allowed when blocked, null when the attempt may proceed.
     */
    public static function hit(Request $request, string $audience, string $subject): ?int
    {
        [$perAccount, $decayMinutes] = self::limits('account', '5,1');
        [$perIp, $ipDecayMinutes] = self::limits('ip', '20,1');
        $accountKey = self::accountKey($audience, $subject, $request);
        $ipKey = self::ipKey($audience, $request);

        foreach ([[$accountKey, $perAccount], [$ipKey, $perIp]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = max(1, RateLimiter::availableIn($key));
                self::recordBlocked($request, $audience, $subject, $key, $retryAfter);

                return $retryAfter;
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
     * @param string  $subject Same subject as passed to hit().
     * @return void
     */
    public static function succeeded(Request $request, string $audience, string $subject): void
    {
        RateLimiter::clear(self::accountKey($audience, $subject, $request));
        [, $ipDecayMinutes] = self::limits('ip', '20,1');
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
     * Hand a refused sign-in to the failed sign-in log (one row per limiter key and block
     * window — the recorder deduplicates). The subject is "id:<account>" or "name:<typed>".
     *
     * @param Request $request
     * @param string  $audience
     * @param string  $subject
     * @param string  $key      Limiter key that blocks.
     * @param int     $seconds  Seconds the block still lasts.
     * @return void
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-login-failure-log
     * @aidlc-adr admin-shell_login-failure-log
     */
    private static function recordBlocked(Request $request, string $audience, string $subject, string $key, int $seconds): void
    {
        $accountId = str_starts_with($subject, 'id:') ? substr($subject, 3) : null;
        $identity = str_starts_with($subject, 'name:') ? substr($subject, 5) : null;

        app(\GP247\Core\Support\LoginFailure\LoginFailureRecorder::class)
            ->throttled($audience, $identity, $accountId, $key, $seconds, $request);
    }

    /**
     * [max attempts, decay minutes] from gp247-config.login_throttle.<key> ("5,1").
     *
     * @param string $key "account" or "ip".
     * @param string $default
     * @return int[]
     */
    private static function limits(string $key, string $default): array
    {
        $parts = explode(',', (string) config('gp247-config.login_throttle.' . $key, $default));

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
        // WHY the "api" prefix: these keys predate the web form using them; renaming
        // would reset every counter running on an upgraded site.
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
