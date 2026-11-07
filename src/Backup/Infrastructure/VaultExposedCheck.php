<?php

namespace GP247\Core\Backup\Infrastructure;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Infrastructure\Storage\VaultExposure;
use GP247\Core\Support\Health\HealthText;

/**
 * "System alerts" row (dashboard + gp247:doctor): the backup folder can be downloaded over the web.
 * The only backup check shown there — every archive of the site would be public (encrypted, but
 * an attacker could then try passwords offline); the other backup warnings stay on the backup screen.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-doctor-checks
 */
class VaultExposedCheck implements AdminAlertCheck
{
    /**
     * @return string
     */
    public function key(): string
    {
        return 'backup_vault_exposed';
    }

    /**
     * @return int Seconds a result is reused (one web request per check).
     */
    public function ttl(): int
    {
        return 3600;
    }

    /**
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert
    {
        if (!BackupFeature::enabled()) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.backup_vault_ok'), 'backup feature off');
        }
        // WHY the stored result: evaluating System alerts never calls the network while a page
        // renders; `gp247:doctor` and the background admin tick (once a day) do the probe.
        $last = VaultExposure::recorded();
        if ($last === null) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.backup_vault_ok'), 'not checked yet — run php artisan gp247:doctor');
        }
        if ($last['exposed'] === true) {
            return AdminAlert::critical(
                $this->key(),
                HealthText::t('admin.alerts.backup_vault_exposed'),
                'the backup folder can be downloaded through APP_URL — point the web root of the site to public/',
                docUrl: HealthText::doc('security-production-config'),
            );
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.backup_vault_ok'), 'backup folder not reachable over the web (checked ' . date('Y-m-d H:i', $last['at']) . ')');
    }
}
