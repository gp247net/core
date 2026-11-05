<?php

namespace GP247\Core\Support\LoginFailure;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes failed sign-ins of the recorded account groups. Never stores a password,
 * keeps at most one row per blocked-attempt window, removes rows past the keep
 * period without cron, and never lets a recording error reach the sign-in.
 *
 * Resolved from the container so a test (or a developer) can replace it.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-log
 * @aidlc-adr admin-shell_login-failure-log
 */
class LoginFailureRecorder
{
    public const REASON_INVALID = 'invalid_credentials';
    public const REASON_THROTTLED = 'throttled';
    public const PRUNE_KEY = 'gp247-login-failure-prune';
    /** Set when a row could not be written; the alert check reads it so a broken log is never "all clear". */
    public const WRITE_ERROR_KEY = 'gp247-login-failure-write-error';
    /** Rows one account group may add per minute across the whole site (an IP-rotating flood cannot fill the disk). */
    public const SITE_CAP_PER_MINUTE = 300;
    private const PRUNE_EVERY_SECONDS = 3600;
    private const PRUNE_CHUNK = 5000;
    private const PRUNE_MAX_CHUNKS = 5;
    private const REPORT_KEY = 'gp247-login-failure-report';
    private const CAP_PREFIX = 'gp247-login-failure-cap|';
    private const THROTTLED_PREFIX = 'gp247-login-failure-throttled|';

    /**
     * Record a failed credential check.
     *
     * @param string          $audience  Account group (auth guard name).
     * @param string|null     $identity  Username / e-mail as typed.
     * @param int|string|null $accountId Matched account id, when the account exists.
     * @param Request|null    $request
     * @return void
     */
    public function failed(string $audience, ?string $identity, $accountId, ?Request $request = null): void
    {
        $this->write($audience, $identity, $accountId, self::REASON_INVALID, $request);
    }

    /**
     * Record that sign-ins are being refused for exceeding the limit — once per
     * limiter key and block window, so a long burst of refused tries stays one row.
     *
     * @param string          $audience
     * @param string|null     $identity
     * @param int|string|null $accountId
     * @param string          $windowKey Limiter key that is blocking.
     * @param int             $seconds   Seconds the block still lasts.
     * @param Request|null    $request
     * @return void
     */
    public function throttled(string $audience, ?string $identity, $accountId, string $windowKey, int $seconds, ?Request $request = null): void
    {
        try {
            // WHY the cache first: refused tries used to cost cache reads only; the settings
            // query now runs once per block window instead of once per refused try.
            if (!Cache::add(self::THROTTLED_PREFIX . sha1($windowKey), 1, max(1, $seconds))) {
                return;
            }
        } catch (\Throwable $e) {
            $this->report($e);

            return;
        }
        $this->write($audience, $identity, $accountId, self::REASON_THROTTLED, $request);
    }

    /**
     * Table the rows go to.
     *
     * @return string
     */
    protected function table(): string
    {
        return GP247_DB_PREFIX . LoginFailureSchema::TABLE;
    }

    /**
     * @param string          $audience
     * @param string|null     $identity
     * @param int|string|null $accountId
     * @param string          $reason
     * @param Request|null    $request
     * @return void
     */
    private function write(string $audience, ?string $identity, $accountId, string $reason, ?Request $request): void
    {
        try {
            if (!LoginFailureSettings::enabled($audience) || !$this->underSiteCap($audience)) {
                return;
            }
            $request = $request ?? request();
            DB::connection(GP247_DB_CONNECTION)->table($this->table())->insert([
                'audience'   => self::clean($audience, 30),
                'channel'    => self::channel($request),
                'identity'   => self::clean($identity, 191),
                'account_id' => self::clean($accountId === null ? null : (string) $accountId, 64),
                'reason'     => $reason,
                'ip'         => self::clean((string) $request->ip(), 45),
                'user_agent' => self::clean((string) $request->userAgent(), 255),
                'created_at' => now(),
            ]);
            $this->prune();
        } catch (\Throwable $e) {
            // WHY swallowed: the log is evidence, never a gate — a missing table or a DB
            // hiccup must not change whether somebody can sign in. The marker makes the
            // alert say so instead of showing "no sign of password guessing".
            try {
                Cache::put(self::WRITE_ERROR_KEY, now()->getTimestamp(), 86400);
            } catch (\Throwable $ignored) {
                // Cache down too: nothing more can be done without blocking the sign-in.
            }
            $this->report($e);
        }
    }

    /**
     * Whether $audience may add another row this minute (site-wide cap).
     *
     * @param string $audience
     * @return bool
     */
    private function underSiteCap(string $audience): bool
    {
        $key = self::CAP_PREFIX . $audience . '|' . now()->format('YmdHi');
        Cache::add($key, 0, 120);

        return (int) Cache::increment($key) <= self::SITE_CAP_PER_MINUTE;
    }

    /**
     * Valid UTF-8 without control characters, cut to $max characters; null when empty.
     *
     * WHY: name and User-Agent are attacker-controlled; an invalid byte would make a
     * strict MySQL / MariaDB refuse the insert and hide the attempt.
     *
     * @param string|null $value
     * @param int         $max
     * @return string|null
     */
    private static function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_scrub($value, 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = Str::limit($value, $max, '');

        return $value === '' ? null : $value;
    }

    /**
     * Remove rows past the keep period, at most once an hour (no cron needed).
     *
     * @return void
     */
    private function prune(): void
    {
        if (!Cache::add(self::PRUNE_KEY, 1, self::PRUNE_EVERY_SECONDS)) {
            return;
        }
        $days = max(1, (int) config('gp247-config.admin.login_failure_keep_days', 30));
        $before = now()->subDays($days);
        // WHY chunks: after a flood one DELETE could lock the table inside a sign-in request.
        for ($i = 0; $i < self::PRUNE_MAX_CHUNKS; $i++) {
            $deleted = DB::connection(GP247_DB_CONNECTION)->table($this->table())
                ->where('created_at', '<', $before)
                ->orderBy('id')
                ->limit(self::PRUNE_CHUNK)
                ->delete();
            if ($deleted < self::PRUNE_CHUNK) {
                return;
            }
        }
    }

    /**
     * `api` for API calls (path under api/ or a JSON request), `web` otherwise.
     *
     * @param Request $request
     * @return string
     */
    private static function channel(Request $request): string
    {
        return ($request->is('api/*') || $request->expectsJson()) ? 'api' : 'web';
    }

    /**
     * @param \Throwable $e
     * @return void
     */
    private function report(\Throwable $e): void
    {
        // WHY once an hour and no message: an attacker can trigger the error on every try,
        // and a query error message carries the bindings (the typed name, the User-Agent).
        try {
            if (function_exists('gp247_report') && Cache::add(self::REPORT_KEY, 1, 3600)) {
                gp247_report('[gp247 login failures] a failed sign-in could not be recorded: ' . get_class($e) . ' (code ' . $e->getCode() . ')');
            }
        } catch (\Throwable $ignored) {
            // Reporting must never reach the sign-in either.
        }
    }
}
