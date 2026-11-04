<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The installer's default account `admin` still signs in with the password `admin`.
 *
 * One bcrypt comparison per evaluation, hence the long ttl
 * (RISK-PERF-admin-alert-dashboard-latency).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class DefaultAdminPasswordCheck implements AdminAlertCheck
{
    private const DEFAULT_USERNAME = 'admin';
    private const DEFAULT_PASSWORD = 'admin';

    /** @return string */
    public function key(): string
    {
        return 'default_admin_password';
    }

    /** @return int */
    public function ttl(): int
    {
        return 3600;
    }

    /**
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert
    {
        $hash = DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'admin_user')
            ->where('username', self::DEFAULT_USERNAME)
            ->where('status', 1)
            ->value('password');

        if (is_string($hash) && $hash !== '' && Hash::check(self::DEFAULT_PASSWORD, $hash)) {
            return AdminAlert::critical(
                $this->key(),
                HealthText::t('admin.alerts.default_admin_password'),
                'the active account "admin" still uses the default password',
                HealthText::route('admin_user.index'),
                HealthText::t('admin.alerts.fix_users'),
                HealthText::doc('security-accounts-and-access'),
            );
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.default_admin_password_ok'), 'no active account uses the default password');
    }
}
