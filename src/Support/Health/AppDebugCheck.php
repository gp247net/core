<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * Debug mode on: error pages expose file paths, configuration and SQL to visitors.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class AppDebugCheck implements AdminAlertCheck
{
    /** @return string */
    public function key(): string
    {
        return 'app_debug';
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
        if ((bool) config('app.debug')) {
            return AdminAlert::critical(
                $this->key(),
                HealthText::t('admin.alerts.app_debug'),
                'APP_DEBUG is on - set APP_DEBUG=false and APP_ENV=production',
                docUrl: HealthText::doc('security-production-config'),
            );
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.app_debug_ok'), 'APP_DEBUG is off');
    }
}
