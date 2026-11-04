<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * No dedicated GP247_ENCRYPTION_KEY: stored secrets are tied to APP_KEY.
 *
 * Doctor row `encryption_key_dedicated` — detail text kept word for word.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004
 * @aidlc-adr admin-shell_system-alert-registry
 */
class EncryptionKeyDedicatedCheck implements AdminAlertCheck
{
    /** @return string */
    public function key(): string
    {
        return 'encryption_key_dedicated';
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
        if ((string) config('gp247-config.security.encryption_key', '') !== '') {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.encryption_key_ok'), 'GP247_ENCRYPTION_KEY set');
        }

        return AdminAlert::warning(
            $this->key(),
            HealthText::t('admin.alerts.encryption_key'),
            'not set — secrets use APP_KEY; set GP247_ENCRYPTION_KEY to insulate them from an APP_KEY change',
            docUrl: HealthText::doc('security-keys-and-backup'),
        );
    }
}
