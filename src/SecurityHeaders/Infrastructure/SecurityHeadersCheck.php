<?php

namespace GP247\Core\SecurityHeaders\Infrastructure;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\SecurityHeaders\Application\HeaderPolicyResolver;
use GP247\Core\SecurityHeaders\Application\SecurityHeaderSettings;
use GP247\Core\SecurityHeaders\Domain\HeaderPolicy;
use GP247\Core\Support\Health\HealthText;

/**
 * "Security headers" entry of the system alerts and of `gp247:doctor`: whether the
 * emergency switch silenced every header, HSTS is set on a site that is not HTTPS, or a
 * header is switched off. Reads settings only — no network call (the live check is the
 * `--probe-headers` flag of the doctor).
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-doctor-checks
 * @aidlc-adr security-headers_doctor-live-probe
 */
final class SecurityHeadersCheck implements AdminAlertCheck
{
    /** Setting key => header name, in the order the screen lists them. */
    private const SWITCHES = [
        SecurityHeaderSettings::FRAME => HeaderPolicy::FRAME,
        SecurityHeaderSettings::NOSNIFF => HeaderPolicy::NOSNIFF,
        SecurityHeaderSettings::REFERRER => HeaderPolicy::REFERRER,
        SecurityHeaderSettings::PERMISSIONS => HeaderPolicy::PERMISSIONS,
    ];

    /**
     * @param HeaderPolicyResolver $resolver
     */
    public function __construct(private readonly HeaderPolicyResolver $resolver)
    {
    }

    /**
     * @return string
     */
    public function key(): string
    {
        return 'security_headers';
    }

    /**
     * @return int Seconds the result may be cached.
     */
    public function ttl(): int
    {
        return 300;
    }

    /**
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert
    {
        $fixUrl = HealthText::route('admin.security_headers');
        $fixLabel = HealthText::t('admin.alerts.fix_security_headers');
        $doc = HealthText::doc('security-production-config');

        if (!$this->resolver->enabled()) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.security_headers_emergency_off'),
                'GP247_SECURITY_HEADERS=0 — no security header is sent', $fixUrl, $fixLabel, $doc);
        }
        $values = SecurityHeaderSettings::values();
        if ($values[SecurityHeaderSettings::HSTS_ENABLED] && !self::isHttps((string) config('app.url'))) {
            return AdminAlert::critical($this->key(), HealthText::t('admin.alerts.security_headers_hsts_without_https'),
                'HSTS is on but APP_URL is not https', $fixUrl, $fixLabel, $doc);
        }
        $off = [];
        foreach (self::SWITCHES as $key => $header) {
            if (!$values[$key]) {
                $off[] = $header;
            }
        }
        if ($off !== []) {
            return new AdminAlert($this->key(), AdminAlert::INFO, HealthText::t('admin.alerts.security_headers_off', ['headers' => implode(', ', $off)]),
                'switched off: ' . implode(', ', $off), $fixUrl, $fixLabel, $doc, $off);
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.security_headers_ok'),
            $values[SecurityHeaderSettings::HSTS_ENABLED] ? 'all headers on, HSTS on' : 'all headers on, HSTS off');
    }

    /**
     * Whether an address uses https.
     *
     * @param string $url
     * @return bool
     */
    public static function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }
}
