<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use Illuminate\Support\Facades\DB;

/**
 * Admin password minimum length below the recommended 12 characters.
 *
 * Reads admin_config directly (not gp247_config_global, which caches for the
 * whole request) so "Check again" right after saving the policy is accurate.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class PasswordPolicyCheck implements AdminAlertCheck
{
    public const RECOMMENDED_MIN = 12;
    private const SHIPPED_DEFAULT_MIN = 6;

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
        $value = DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'admin_config')
            ->where('store_id', GP247_STORE_ID_GLOBAL)
            ->where('key', 'admin_password_min')
            ->value('value');
        $min = is_numeric($value) ? (int) $value : self::SHIPPED_DEFAULT_MIN;

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
