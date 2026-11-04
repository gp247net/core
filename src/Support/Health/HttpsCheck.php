<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * Site URL not on HTTPS, or the session cookie not marked secure.
 *
 * Reads configuration (APP_URL, session.secure) rather than the current request,
 * so the CLI and the dashboard agree and a TLS-terminating proxy without
 * TRUSTED_PROXIES does not produce a false alarm. Critical only in production;
 * a local machine without TLS is merely informational.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class HttpsCheck implements AdminAlertCheck
{
    /** @return string */
    public function key(): string
    {
        return 'https';
    }

    /** @return int */
    public function ttl(): int
    {
        return 600;
    }

    /**
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert
    {
        $problems = [];
        if (!str_starts_with(strtolower((string) config('app.url')), 'https://')) {
            $problems[] = 'APP_URL does not start with https://';
        }
        if (config('session.secure') !== true) {
            $problems[] = 'session cookie is not secure (set SESSION_SECURE_COOKIE=true)';
        }

        if ($problems === []) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.https_ok'), 'APP_URL uses https and the session cookie is secure');
        }

        $message = HealthText::t('admin.alerts.https');
        $detail = implode('; ', $problems);
        $doc = HealthText::doc('security-production-config');

        return app()->environment('production')
            ? AdminAlert::critical($this->key(), $message, $detail, docUrl: $doc)
            : AdminAlert::info($this->key(), $message, $detail, docUrl: $doc);
    }
}
