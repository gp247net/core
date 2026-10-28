<?php

namespace GP247\Core\Api\Support;

use GP247\Core\Api\Contracts\LoginChallenge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Seam for a second sign-in step (MFA) supplied by plugins through
 * `gp247-config.api.login_challenge.<audience>`. After the password check, core issues
 * a single-use ticket (hashed, default cache store, 5 minutes) and the client finishes
 * on `POST login/challenge`. A plugin that throws denies the sign-in (fail-closed);
 * wrong answers count against the account's login throttle.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-login-challenge-seam, US-CMP-api-login-throttle
 * @aidlc-adr compat-foundation_api-token-rotation
 */
final class LoginChallenges
{
    /** Ticket lifetime in seconds. */
    public const TTL = 300;

    /** Wrong answers allowed on one ticket, from any IP, before it is destroyed. */
    public const MAX_ATTEMPTS = 5;

    private const CACHE_PREFIX = 'gp247-api-challenge:';

    /**
     * v2 sign-in gate: null when no challenge applies, otherwise the 401
     * `challenge_required` response carrying a ticket (or `challenge_invalid` when the
     * plugin failed).
     *
     * @param Model   $owner    Owner whose password was verified.
     * @param string  $audience "customer" or "admin".
     * @param Request $request
     * @param array   $opts     Token options to use once the challenge is passed (see ApiSessions::open()).
     * @return JsonResponse|null
     */
    public static function gate(Model $owner, string $audience, Request $request, array $opts): ?JsonResponse
    {
        try {
            $challenge = self::pending($owner, $audience, $request);
        } catch (\Throwable $e) {
            gp247_report($e);
            return self::invalid();
        }
        if (!$challenge) {
            return null;
        }

        $ticket = Str::random(40);
        Cache::put(self::key($ticket), [
            'audience' => $audience,
            'owner'    => [get_class($owner), (string) $owner->getKey()],
            'class'    => get_class($challenge),
            'opts'     => $opts,
            'attempts' => 0,
            'expires'  => time() + self::TTL,
        ], self::TTL);

        try {
            $hints = $challenge->describe($owner);
        } catch (\Throwable $e) {
            gp247_report($e);
            $hints = [];
        }

        return response()->json([
            'error'     => 1,
            'msg'       => 'Additional sign-in verification required',
            'code'      => 'challenge_required',
            'challenge' => [
                'ticket'     => $ticket,
                'method'     => (string) ($hints['method'] ?? ''),
                'meta'       => (array) ($hints['meta'] ?? []),
                'expires_in' => self::TTL,
            ],
        ], 401);
    }

    /**
     * v1 sign-in gate: v1 has no endpoint to finish a challenge, so a required one is
     * refused outright (clients must move to v2).
     *
     * @param Model   $owner
     * @param string  $audience
     * @param Request $request
     * @return JsonResponse|null
     */
    public static function refuseForV1(Model $owner, string $audience, Request $request): ?JsonResponse
    {
        try {
            $challenge = self::pending($owner, $audience, $request);
        } catch (\Throwable $e) {
            gp247_report($e);
            return self::invalid();
        }
        return $challenge
            ? ApiErrorResponder::respond(401, 'challenge_required', 'Additional sign-in verification required; use API v2 (login/challenge)')
            : null;
    }

    /**
     * Finish a challenge: on success the ticket is consumed and [owner, token options] is returned.
     *
     * @param Request $request  Carries `ticket` plus the plugin's own fields.
     * @param string  $audience
     * @return array{0: Model, 1: array}|JsonResponse
     */
    public static function complete(Request $request, string $audience)
    {
        $request->validate(['ticket' => 'required|string|max:100']);
        $key = self::key((string) $request->input('ticket'));
        $payload = Cache::get($key);
        if (!is_array($payload) || ($payload['audience'] ?? null) !== $audience) {
            return self::invalid();
        }

        [$class, $id] = $payload['owner'];
        $owner = class_exists($class) ? $class::find($id) : null;
        $challenge = class_exists($payload['class']) ? app($payload['class']) : null;
        if (!$owner || (int) $owner->status !== 1 || !$challenge instanceof LoginChallenge) {
            Cache::forget($key);
            return self::invalid();
        }

        $subject = ApiLoginThrottle::subject($owner->getKey(), '');
        if ($blocked = ApiLoginThrottle::attempt($request, $audience, $subject)) {
            Cache::forget($key);
            return $blocked;
        }

        // WHY: one answer per ticket at a time, so a code replayed in parallel cannot open two sessions.
        if (!Cache::add($key . ':claim', 1, 30)) {
            return self::invalid();
        }
        try {
            $passed = $challenge->verify($owner, $request) === true;
        } catch (\Throwable $e) {
            gp247_report($e);
            $passed = false;
        }
        if (!$passed) {
            self::countFailure($key, $payload);
            Cache::forget($key . ':claim');
            return self::invalid();
        }

        Cache::forget($key);
        Cache::forget($key . ':claim');
        ApiLoginThrottle::succeeded($request, $audience, $subject);
        return [$owner, (array) $payload['opts']];
    }

    /**
     * Count a wrong answer on the ticket; destroy it after MAX_ATTEMPTS so the code cannot
     * be guessed from many IPs (the login throttle is per account and IP).
     *
     * @param string $key
     * @param array  $payload
     * @return void
     */
    private static function countFailure(string $key, array $payload): void
    {
        $payload['attempts'] = (int) ($payload['attempts'] ?? 0) + 1;
        $left = (int) ($payload['expires'] ?? 0) - time();
        if ($payload['attempts'] >= self::MAX_ATTEMPTS || $left <= 0) {
            Cache::forget($key);
            return;
        }
        Cache::put($key, $payload, $left);
    }

    /**
     * First registered challenge that applies to $owner, or null.
     *
     * @param Model   $owner
     * @param string  $audience
     * @param Request $request
     * @return LoginChallenge|null
     * @throws \UnexpectedValueException When a registered entry is not a LoginChallenge.
     */
    private static function pending(Model $owner, string $audience, Request $request): ?LoginChallenge
    {
        foreach ((array) config('gp247-config.api.login_challenge.' . $audience, []) as $entry) {
            $challenge = is_string($entry) && class_exists($entry) ? app($entry) : $entry;
            if (!$challenge instanceof LoginChallenge) {
                // WHY: a broken registration must not silently switch MFA off.
                throw new \UnexpectedValueException('Invalid login challenge registered for ' . $audience);
            }
            if ($challenge->required($owner, $request) === true) {
                return $challenge;
            }
        }
        return null;
    }

    /**
     * Cache key of a ticket (only its hash is stored).
     *
     * @param string $ticket
     * @return string
     */
    private static function key(string $ticket): string
    {
        return self::CACHE_PREFIX . hash('sha256', $ticket);
    }

    /**
     * 401 for an unknown, expired, wrong or failed challenge.
     *
     * @return JsonResponse
     */
    private static function invalid(): JsonResponse
    {
        return ApiErrorResponder::respond(401, 'challenge_invalid', 'Sign-in verification failed');
    }
}
