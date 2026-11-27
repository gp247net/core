<?php

namespace GP247\Core\Support\Report;

use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Api\Sync\OutboundUrlRejected;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends an error report to the outside channels of the admin "Webhook" screen — Slack,
 * Google Chat and a third-party webhook — as a JSON POST of {"text": "..."}. An empty
 * field falls back to .env (LOG_SLACK_WEBHOOK_URL, GP247_REPORT_WEBHOOK_URL), so a report
 * still goes out when the database is down. Every send is bounded in time, never follows a
 * redirect and only calls a public https address (pinned to the addresses just checked).
 * The same report goes out once per throttle window, and at most max_per_minute reports
 * leave per minute; a broken cache sends anyway. A failed send is written to the local
 * `daily` log only and never throws: reporting an error must not cause another one.
 *
 * Sent synchronously on purpose: some reports are written right before the process exits
 * (database unreachable), where a deferred send would never run.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-report-channels-env
 * @aidlc-adr compat-foundation_report-channels-env
 */
final class ReportNotifier
{
    /** Fields of the Webhook screen (admin_config group "global"). */
    public const SLACK_KEY = 'LOG_SLACK_WEBHOOK_URL';
    public const GOOGLE_CHAT_KEY = 'GOOGLE_CHAT_WEBHOOK_URL';
    public const THIRD_PARTY_KEY = 'THIRD_PARTY_WEBHOOK_URL';

    /**
     * Send $text to every configured channel unless the same report was sent within the window.
     *
     * @param string $text        Full report text (time line, site line, message).
     * @param string $fingerprint Identity of the report for throttling (same failure = same value).
     * @return void
     */
    public static function send(string $text, string $fingerprint): void
    {
        $targets = self::targets();
        if ($targets === [] || !self::allowed($fingerprint)) {
            return;
        }
        $body = json_encode(
            ['text' => mb_substr($text, 0, max(1, (int) config('gp247-config.report.max_length', 4000)))],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        foreach ($targets as $url) {
            self::post($url, (string) $body);
        }
    }

    /**
     * Channels to send to: each screen field, an empty one falling back to .env (Slack, third party).
     *
     * @return string[]
     */
    public static function targets(): array
    {
        $slack = self::setting(self::SLACK_KEY) ?: trim((string) config('logging.channels.slack.url'));
        $google = self::setting(self::GOOGLE_CHAT_KEY);
        $third = self::setting(self::THIRD_PARTY_KEY) ?: trim((string) config('gp247-config.report.webhook_url'));

        return array_values(array_unique(array_filter([$slack, $google, $third], fn ($url) => $url !== '')));
    }

    /**
     * A field of the Webhook screen, or '' (also when the database cannot be read).
     *
     * @param string $key
     * @return string
     */
    private static function setting(string $key): string
    {
        try {
            return function_exists('gp247_config_global') ? trim((string) gp247_config_global($key, '')) : '';
        } catch (\Throwable $e) {
            // WHY: this runs while reporting an error — often the database itself is the error.
            return '';
        }
    }

    /**
     * Whether this report may go out now (throttle per fingerprint + cap per minute).
     *
     * @param string $fingerprint
     * @return bool
     */
    private static function allowed(string $fingerprint): bool
    {
        try {
            $window = max(1, (int) config('gp247-config.report.throttle_seconds', 60));
            if (!Cache::add('gp247:report:fp:' . sha1($fingerprint), 1, $window)) {
                return false;
            }
            $minuteKey = 'gp247:report:minute:' . date('YmdHi');
            Cache::add($minuteKey, 0, 120);

            return (int) Cache::increment($minuteKey) <= max(1, (int) config('gp247-config.report.max_per_minute', 30));
        } catch (\Throwable $e) {
            // WHY fail-open: a broken cache must not silence error reports.
            return true;
        }
    }

    /**
     * POST $body to $url; any failure is logged locally with the host only (the URL is a secret).
     *
     * @param string $url
     * @param string $body JSON.
     * @return void
     */
    private static function post(string $url, string $body): void
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: 'invalid-url');
        try {
            $target = OutboundUrlGuard::check($url);
        } catch (OutboundUrlRejected $e) {
            self::logFailure($host, 'refused ' . $e->getMessage());
            return;
        }
        if (!defined('CURLOPT_RESOLVE')) {
            // WHY fail closed: without cURL the connection cannot be pinned to the checked addresses.
            self::logFailure($host, 'curl_missing');
            return;
        }
        $timeout = max(1, (int) config('gp247-config.report.timeout', 3));
        $pins = array_map(
            fn ($ip) => $target['host'] . ':' . $target['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip),
            $target['ips']
        );
        try {
            $response = Http::withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => $pins]])
                ->connectTimeout(min(1, $timeout))
                ->timeout($timeout)
                ->withHeaders(['User-Agent' => 'GP247-Report/1.0'])
                ->withBody($body, 'application/json')
                ->post($url);
            if (!$response->successful()) {
                self::logFailure($host, 'http_' . $response->status());
            }
        } catch (\Throwable $e) {
            self::logFailure($host, 'connection ' . get_class($e));
        }
    }

    /**
     * @param string $host
     * @param string $reason
     * @return void
     */
    private static function logFailure(string $host, string $reason): void
    {
        try {
            Log::channel('daily')->warning('gp247 report webhook failed (' . $host . '): ' . $reason);
        } catch (\Throwable $e) {
            // WHY: nothing left to report to; never throw from the reporting path.
        }
    }
}
