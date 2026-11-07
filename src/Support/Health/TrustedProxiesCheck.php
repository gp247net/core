<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * TRUSTED_PROXIES trusts every visitor: "REMOTE_ADDR" (behind the bundled nginx,
 * php-fpm receives the visitor's own address as REMOTE_ADDR over FastCGI), "*",
 * "**" or a catch-all range. Any visitor can then set their own IP through
 * X-Forwarded-For — login throttling, the sign-in log and IP rules stop holding.
 *
 * Reads configuration rather than the current request, so the CLI and the
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
     * Warn when TRUSTED_PROXIES trusts every client; pass when it is empty or lists
     * explicit ranges only.
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

        $detail = $entries === []
            ? 'TRUSTED_PROXIES is empty - no proxy is trusted'
            : 'TRUSTED_PROXIES lists ' . count($entries) . ' explicit range(s)';

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.trusted_proxies_ok'), $detail);
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
