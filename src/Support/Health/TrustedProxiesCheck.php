<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * TRUSTED_PROXIES trusts every visitor: "REMOTE_ADDR" (behind the bundled nginx,
 * php-fpm receives the visitor's own address as REMOTE_ADDR over FastCGI), "*",
 * "**" or a catch-all range. Any visitor can then set their own IP through
 * X-Forwarded-For — login throttling, the sign-in log and IP rules stop holding.
 *
 * Also reports a missing proxy: the last signed-in admin request came through a
 * proxy that TRUSTED_PROXIES does not list (ProxyTrustObservation), so URLs are
 * http:// and every visitor shows the proxy's IP. Neither condition reads the
 * current request — configuration plus the recorded signal — so the CLI and the
 * dashboard agree.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-DEP-trusted-proxy-explicit
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
class TrustedProxiesCheck implements AdminAlertCheck
{
    /** Values that trust every client, compared case-insensitively. */
    private const TRUST_EVERYONE = ['remote_addr', '*', '**', '0.0.0.0/0', '::/0'];

    /** @return string */
    public function key(): string
    {
        return 'trusted_proxies';
    }

    /** @return int */
    public function ttl(): int
    {
        return 600;
    }

    /**
     * Warn when TRUSTED_PROXIES trusts every client, or when the last admin request came
     * through a proxy it does not list; pass otherwise.
     *
     * @return AdminAlert
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    public function evaluate(): AdminAlert
    {
        $entries = $this->entries(config('trustedproxy.proxies'));
        $offending = array_values(array_filter(
            $entries,
            fn (string $entry) => in_array(strtolower($entry), self::TRUST_EVERYONE, true)
        ));

        if ($offending !== []) {
            return AdminAlert::warning(
                $this->key(),
                HealthText::t('admin.alerts.trusted_proxies'),
                'TRUSTED_PROXIES contains ' . implode(', ', $offending)
                    . ' - every visitor can set their own IP and scheme through X-Forwarded-*; list only the IP ranges of your real proxy, or leave it empty',
                docUrl: HealthText::doc('security-production-config'),
            );
        }

        $missing = $this->untrustedHop($entries);
        if ($missing !== null) {
            return $missing;
        }

        $detail = $entries === []
            ? 'TRUSTED_PROXIES is empty - no proxy is trusted'
            : 'TRUSTED_PROXIES lists ' . count($entries) . ' explicit range(s)';

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.trusted_proxies_ok'), $detail);
    }

    /**
     * Warning for a proxy in front of the site that TRUSTED_PROXIES does not list, from
     * the last recorded admin request; null when there is no such signal or the current
     * configuration already covers that proxy (fixed before the next admin request).
     *
     * @param array<int, string> $entries Normalised TRUSTED_PROXIES entries.
     * @return AdminAlert|null
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-system-alerts
     */
    private function untrustedHop(array $entries): ?AdminAlert
    {
        $signal = ProxyTrustObservation::latest();
        if ($signal === null || $signal['trusted'] || ($entries !== [] && IpUtils::checkIp($signal['peer'], $entries))) {
            return null;
        }

        $hint = ProxyTrustObservation::isInternal($signal['peer'])
            ? 'add the Docker network / local proxy range (e.g. 172.16.0.0/12) and publish the container port on 127.0.0.1 only'
            : 'check that this address belongs to your CDN / load balancer (its published IP ranges) before adding those ranges - never add an address you do not recognise';

        return AdminAlert::warning(
            $this->key(),
            HealthText::t('admin.alerts.trusted_proxies_untrusted_hop'),
            'admin request at ' . gmdate('Y-m-d\TH:i:s\Z', $signal['at']) . ' came through ' . $signal['peer']
                . ', which TRUSTED_PROXIES does not list - URLs are generated as http:// and visitors show that IP; ' . $hint
                . ' (list every proxy in front of PHP)',
            docUrl: HealthText::doc('security-production-config'),
        );
    }

    /**
     * Normalise the configured value (comma-separated string or array) to trimmed,
     * non-empty entries.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private function entries(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn (string $item) => $item !== ''));
    }
}
