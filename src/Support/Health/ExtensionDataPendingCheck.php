<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Library\ExtensionDataUpdater;

/**
 * Installed extensions whose files are newer than the version core recorded —
 * their data hook has not run (updated by git pull / composer / FTP).
 * Throws when the database cannot be read; callers treat that as "skipped".
 *
 * Doctor row `extension_data_pending` — detail text kept word for word.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-system-alerts, US-CLI-004, US-PLG-local-update-data-converge
 * @aidlc-adr admin-shell_system-alert-registry
 */
class ExtensionDataPendingCheck implements AdminAlertCheck
{
    /** @return string */
    public function key(): string
    {
        return 'extension_data_pending';
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
        $pending = (new ExtensionDataUpdater)->pending();
        if ($pending === []) {
            return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.extension_data_pending_ok'), 'every installed extension is up to date');
        }

        $items = array_map(
            static fn ($p) => $p['type'] . '/' . $p['key'] . ' ' . ($p['from'] ?? 'unknown') . ' -> ' . $p['to'],
            array_values($pending)
        );

        return AdminAlert::warning(
            $this->key(),
            HealthText::t('admin.alerts.extension_data_pending', ['count' => count($items)]),
            count($items) . ' extension(s) updated on disk but not in the database - run: php artisan gp247:ext-update --local --all (or gp247:update)',
            HealthText::route('admin_plugin.index'),
            HealthText::t('admin.alerts.fix_plugins'),
            HealthText::doc('security-updates-and-incidents'),
            $items,
        );
    }
}
