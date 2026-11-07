<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Storage\VaultExposure;

/**
 * Rows of the backup area for `gp247:doctor` — "can this site really be restored?". Each row has
 * a level (pass · warn · fail) and says how to fix it. Only "backup folder downloadable over the
 * web" fails (a real incident); the rest warn. Feature off or tables missing ⇒ one row, no error.
 * Running it is also one of the moments the "too old" e-mail can go out.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-doctor-checks
 */
final class BackupDoctor
{
    /**
     * @param KeyService|null $keys
     */
    public function __construct(private ?KeyService $keys = null)
    {
    }

    /**
     * @return list<array{name: string, status: string, detail: string, items?: list<string>}>
     */
    public function rows(): array
    {
        try {
            if (!BackupFeature::enabled()) {
                return [self::row('backup_feature', 'pass', 'off — no backup check (Admin > Backup > Settings to turn it on)')];
            }
            $keys = $this->keys ?? app(KeyService::class);
            $rows = [
                $keys->isSetUp()
                    ? self::row('backup_setup', 'pass', 'backup password set up')
                    : self::row('backup_setup', 'warn', 'no backup password yet — set it up in Admin > Backup (no backup can be made before)'),
                $this->lastSuccess(),
                $this->schedule(),
                $this->vault(),
                ...$this->destination(),
                $this->unassigned(),
                BackupNotifier::mailEnabled()
                    ? self::row('backup_mail', 'pass', 'e-mail on: failures and "too old" are mailed')
                    : self::row('backup_mail', 'warn', 'e-mail sending is off — backup failures and "too old" alerts are not mailed (Admin > Configuration > Email)'),
            ];
            (new StaleAlert($keys))->sendIfDue();

            return $rows;
        } catch (\Throwable $e) {
            return [self::row('backup_feature', 'warn', 'backup checks could not run: ' . class_basename($e))];
        }
    }

    /**
     * @return array
     */
    private function lastSuccess(): array
    {
        $age = (new ScheduleClock)->rpo()['last_ok_hours'];
        $limit = DashboardStatus::staleHours();
        if ($age === null) {
            return self::row('backup_last_success', 'warn', 'no verified backup yet — make one in Admin > Backup');
        }
        if ($age > $limit) {
            return self::row('backup_last_success', 'warn', 'newest verified backup is ' . $age . ' h old (limit ' . $limit . ' h) — check the schedule');
        }

        return self::row('backup_last_success', 'pass', 'newest verified backup ' . $age . ' h ago');
    }

    /**
     * @return array
     */
    private function schedule(): array
    {
        $settings = ScheduleSettings::read();
        $clock = new ScheduleClock;

        return match (true) {
            $settings['mode'] === ScheduleSettings::MODE_OFF => self::row('backup_schedule', 'warn', 'schedule off — only manual backups (Admin > Backup > Settings)'),
            $clock->cronMissed() => self::row('backup_schedule', 'warn', 'cron mode, but no scheduled backup for 2 cycles — is `php artisan schedule:run` in the crontab?'),
            $settings['mode'] === ScheduleSettings::MODE_VISIT => self::row('backup_schedule', 'pass', 'every ' . $settings['hours'] . ' h while an admin has the admin open (no admin visit ⇒ no new backup)'),
            default => self::row('backup_schedule', 'pass', 'cron, every ' . $settings['hours'] . ' h at ' . $settings['at']),
        };
    }

    /**
     * @return array
     */
    private function vault(): array
    {
        $exposed = VaultExposure::probeAndRecord(app(LocalVault::class));

        return match ($exposed) {
            true => self::row('backup_vault_exposed', 'fail', 'the backup folder can be downloaded through APP_URL — point the web root of the site to public/ now'),
            null => self::row('backup_vault_exposed', 'pass', 'skipped (APP_URL did not answer)'),
            default => self::row('backup_vault_exposed', 'pass', 'backup folder not reachable over the web'),
        };
    }

    /**
     * @return list<array>
     */
    private function destination(): array
    {
        $config = new DestinationConfig;
        $current = $config->current();
        if ($current === null) {
            return [self::row('backup_destination', 'pass', 'none — copies stay on this server only (add an external destination for the 3-2-1 rule)')];
        }
        $type = (new DestinationRegistry)->type($current['type']);
        $report = $config->report();
        $row = match (true) {
            $type === null || !$type->available() => self::row('backup_destination', 'warn', $current['type'] . ': the library it needs is not installed — copies fail'),
            $report === null => self::row('backup_destination', 'warn', $current['type'] . ': never tested — Test the connection in Admin > Backup > Settings'),
            !$report->ok() => self::row('backup_destination', 'warn', $current['type'] . ': last test failed (' . ($report->error ?? 'missing ' . implode(', ', $report->capabilities->missing())) . ')'),
            default => self::row('backup_destination', 'pass', $current['type'] . ': write, read, list ok (tested ' . date('Y-m-d H:i', $report->at) . ')'),
        };
        $delete = $report !== null && $report->capabilities->delete
            ? self::row('backup_destination_can_delete', 'warn', 'the credential may delete — an attacker on this site could erase the copies; use a write-only credential with Object Lock')
            : self::row('backup_destination_can_delete', 'pass', 'credential cannot delete (or not tested)');

        return [$row, $delete];
    }

    /**
     * @return array
     */
    private function unassigned(): array
    {
        $registry = new DataGroupRegistry;
        $raw = $registry->unassignedRaw();
        if ($raw !== []) {
            return self::row('backup_unassigned_tables', 'warn', count($raw) . ' table(s) without the site prefix belong to no data group and are NOT backed up — the plugin owning them should declare "dataTables"', array_values($raw));
        }

        return self::row('backup_unassigned_tables', 'pass', 'every table belongs to a data group (' . count($registry->unassigned()) . ' in "Other")');
    }

    /**
     * @param string       $name
     * @param string       $status
     * @param string       $detail
     * @param list<string> $items
     * @return array
     */
    private static function row(string $name, string $status, string $detail, array $items = []): array
    {
        $row = ['name' => $name, 'status' => $status, 'detail' => $detail];
        if ($items !== []) {
            $row['items'] = $items;
        }

        return $row;
    }
}
