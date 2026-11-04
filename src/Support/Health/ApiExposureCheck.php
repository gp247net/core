<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Api\Support\ApiSwitch;

/**
 * REST API (and therefore MCP, which hangs off the same master switch) is on.
 *
 * Informational only: the API is a legitimate feature; the alert reminds the
 * owner to keep it off when unused.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class ApiExposureCheck implements AdminAlertCheck
{
    /** @return string */
    public function key(): string
    {
        return 'api_exposure';
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
        $on = ApiSwitch::serverEnabled() && (ApiSwitch::states()[ApiSwitch::MASTER] ?? true);

        if (!$on) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.api_exposure_ok'), 'REST API and MCP are off');
        }

        return AdminAlert::info(
            $this->key(),
            HealthText::t('admin.alerts.api_exposure'),
            'REST API is on (MCP too when installed) - turn it off in API configuration when unused',
            HealthText::route('admin_api_connection.index'),
            HealthText::t('admin.alerts.fix_api'),
            HealthText::doc('security-built-in'),
        );
    }
}
