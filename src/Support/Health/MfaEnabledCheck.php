<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * Two-factor sign-in (free plugin "MFA") not installed or not active.
 *
 * Reads the extension state by its string key through core's own helper — core
 * never imports a class of the plugin (NFR-MAINT-001).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class MfaEnabledCheck implements AdminAlertCheck
{
    private const PLUGIN_KEY = 'MFA';

    /** @return string */
    public function key(): string
    {
        return 'mfa_enabled';
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
        $active = function_exists('gp247_extension_check_active')
            && (bool) gp247_extension_check_active('Plugins', self::PLUGIN_KEY);

        if ($active) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.mfa_ok'), 'plugin MFA is active');
        }

        return AdminAlert::warning(
            $this->key(),
            HealthText::t('admin.alerts.mfa'),
            'plugin MFA (two-factor sign-in) is not installed or not active',
            HealthText::route('admin_plugin.index'),
            HealthText::t('admin.alerts.fix_plugins'),
            HealthText::doc('security-accounts-and-access'),
        );
    }
}
