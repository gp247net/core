<?php

namespace GP247\Core\Support\Health;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Remembers whether the last signed-in admin request that came through a proxy came
 * from a proxy listed in TRUSTED_PROXIES.
 *
 * A missing hop (CDN -> host nginx -> Docker with only the CDN ranges listed) only
 * shows on a real request: Laravel then ignores every X-Forwarded-* header, URLs
 * become http:// and the browser blocks Livewire as mixed content. The CLI
 * (`gp247:doctor`) has no request, and the dashboard block loads through the very
 * Livewire request that breaks, so the signal is recorded on the admin page request
 * itself and read back by TrustedProxiesCheck.
 *
 * Only an Administrator's requests are recorded (the caller is admin.auth), so a
 * visitor or another staff account sending X-Forwarded-* cannot raise the warning.
 * A public peer is recorded only when it shows the symptom (APP_URL is https, the
 * request is not seen as HTTPS); the check then asks to verify the address before
 * listing it, so a planted signal cannot talk the owner into trusting an attacker.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_system-alert-registry
 */
final class ProxyTrustObservation
{
    public const CACHE_KEY = 'gp247_proxy_trust_observation';

    /** Days a signal is kept when no admin request refreshes it. */
    private const KEEP_DAYS = 30;

    /** An unchanged signal is rewritten at most this often (seconds). */
    private const REFRESH_SECONDS = 600;

    /**
     * Record the proxy trust of a signed-in admin request that came through a proxy.
     *
     * A request counts as proxied when it carries X-Forwarded-Proto, or X-Forwarded-For
     * from an internal address (a proxy on the same host or network). X-Forwarded-For
     * alone from a public address is skipped: a forward proxy on the admin's own network
     * adds it, and it is not a proxy of the site. A public peer is kept only while an
     * https site sees the request as plain http (the symptom of a missing CDN range).
     *
     * @param Request $request Request after TrustProxies has run.
     * @return void
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    public static function record(Request $request): void
    {
        try {
            $peer = (string) $request->server->get('REMOTE_ADDR', '');
            if (filter_var($peer, FILTER_VALIDATE_IP) === false) {
                return;
            }
            $proxied = $request->headers->has('X-Forwarded-Proto')
                || ($request->headers->has('X-Forwarded-For') && self::isInternal($peer));
            if (!$proxied) {
                return;
            }
            if (!self::isInternal($peer) && !self::httpsSiteServedAsHttp($request)) {
                return;
            }

            $trusted = $request->isFromTrustedProxy();
            $previous = self::latest();
            $now = time();
            // WHY: one cache read per admin request; write only when the answer changes or
            // the stored time gets stale, so the cache store is not written on every page.
            if ($previous !== null
                && $previous['trusted'] === $trusted
                && $previous['peer'] === $peer
                && $now - $previous['at'] < self::REFRESH_SECONDS) {
                return;
            }

            Cache::put(self::CACHE_KEY, ['trusted' => $trusted, 'peer' => $peer, 'at' => $now], now()->addDays(self::KEEP_DAYS));
        } catch (\Throwable $e) {
            // WHY: a broken cache store must never block an admin page.
            gp247_report('ProxyTrustObservation::record: ' . $e->getMessage());
        }
    }

    /**
     * The last recorded signal, or null when none (or the stored value is unusable).
     *
     * @return array{trusted: bool, peer: string, at: int}|null
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    public static function latest(): ?array
    {
        try {
            $value = Cache::get(self::CACHE_KEY);
        } catch (\Throwable $e) {
            gp247_report('ProxyTrustObservation::latest: ' . $e->getMessage());

            return null;
        }

        if (!is_array($value)
            || !is_bool($value['trusted'] ?? null)
            || filter_var($value['peer'] ?? null, FILTER_VALIDATE_IP) === false
            || !is_int($value['at'] ?? null)) {
            return null;
        }

        return ['trusted' => $value['trusted'], 'peer' => $value['peer'], 'at' => $value['at']];
    }

    /**
     * Whether APP_URL is https while the request is not seen as HTTPS — what a CDN or load
     * balancer missing from TRUSTED_PROXIES looks like from PHP.
     *
     * @param Request $request
     * @return bool
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    private static function httpsSiteServedAsHttp(Request $request): bool
    {
        return str_starts_with(strtolower((string) config('app.url')), 'https://') && !$request->isSecure();
    }

    /**
     * Whether an address is private, loopback or link-local (a proxy on the same host
     * or network rather than a CDN / load balancer on the Internet).
     *
     * @param string $ip
     * @return bool
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    public static function isInternal(string $ip): bool
    {
        return IpUtils::isPrivateIp($ip);
    }
}
