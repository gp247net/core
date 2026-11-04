<?php

namespace GP247\Core\Support\Health;

use Carbon\Carbon;
use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * The installed version line is close to (≤ 6 months) or past its end of
 * security support.
 *
 * The end month is `gp247.support_until` (YYYY-MM), a hard-coded value that must
 * match SECURITY.md and the public release page (RISK-TECH-support-line-date-drift).
 * No network call.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class SupportLineCheck implements AdminAlertCheck
{
    private const WARN_MONTHS_BEFORE = 6;

    /** @return string */
    public function key(): string
    {
        return 'support_line';
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
        $until = (string) config('gp247.support_until', '');
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $until) !== 1) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.support_line_unknown'), 'support_until is not configured');
        }

        $end = Carbon::createFromFormat('Y-m-d', $until . '-01')->endOfMonth();
        $month = $end->format('m/Y');
        $now = Carbon::now();
        $doc = HealthText::doc('security-updates-and-incidents');

        if ($now->greaterThan($end)) {
            return AdminAlert::critical(
                $this->key(),
                HealthText::t('admin.alerts.support_line_ended', ['month' => $month]),
                'this version line stopped receiving security fixes after ' . $until . ' - upgrade to a supported line',
                docUrl: $doc,
            );
        }

        if ($now->greaterThanOrEqualTo($end->copy()->subMonthsNoOverflow(self::WARN_MONTHS_BEFORE))) {
            return AdminAlert::warning(
                $this->key(),
                HealthText::t('admin.alerts.support_line_soon', ['month' => $month]),
                'this version line receives security fixes until ' . $until,
                docUrl: $doc,
            );
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.support_line_ok', ['month' => $month]), 'security fixes until ' . $until);
    }
}
