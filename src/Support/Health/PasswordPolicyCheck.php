<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Support\Password\PasswordPolicy;

/**
 * Admin password minimum length below the recommended 12 characters.
 *
 * Reads through PasswordPolicy — straight from admin_config, not gp247_config_global
 * which caches for the whole request — so "Check again" right after saving the policy
 * is accurate, and a missing row means the same default every password path uses.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004, US-admin-shell-password-policy-single-source
 * @aidlc-adr admin-shell_system-alert-registry, admin-shell_password-policy-registry
 */
class PasswordPolicyCheck implements AdminAlertCheck
{
    public const RECOMMENDED_MIN = 12;

    /** @return string */
    public function key(): string
    {
        return 'password_policy';
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
        // WHY PasswordPolicy: the same value (and the same default when the row is missing)
        // every password path enforces (US-admin-shell-password-policy-single-source).
        $min = PasswordPolicy::for('admin')->min();

        if ($min < self::RECOMMENDED_MIN) {
            return AdminAlert::warning(
                $this->key(),
                HealthText::t('admin.alerts.password_policy', ['min' => $min]),
                'admin password minimum length is ' . $min . ' (recommended: ' . self::RECOMMENDED_MIN . ' or more)',
                HealthText::route('admin_password_policy.index'),
                HealthText::t('admin.alerts.fix_password_policy'),
                HealthText::doc('security-accounts-and-access'),
            );
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.password_policy_ok'), 'admin password minimum length is ' . $min);
    }
}
