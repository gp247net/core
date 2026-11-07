<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Models\AdminConfig;

/**
 * The "backup too old" e-mail: at most once a day, while the schedule is on and the newest good
 * backup made here is older than `gp247-config.backup.stale_hours` (or none exists since the
 * backup password was set up). It is checked from the moments a request exists — a background
 * tick of an admin page, a scheduled run, `gp247:doctor`: a site with no cron and no admin visit
 * has no request to send it from (stated next to the recovery point on the backup screen).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
final class StaleAlert
{
    public const MAILED_AT = 'backup_stale_mailed_at';
    private const EVERY_SECONDS = 86400;

    /**
     * @param KeyService|null $keys
     */
    public function __construct(private ?KeyService $keys = null)
    {
    }

    /**
     * @return bool Whether a mail was sent now.
     */
    public function sendIfDue(): bool
    {
        try {
            $settings = ScheduleSettings::read();
            $keys = $this->keys ?? app(KeyService::class);
            if ($settings['mode'] === ScheduleSettings::MODE_OFF || !BackupFeature::enabled() || !$keys->isSetUp()) {
                return false;
            }
            $age = (new ScheduleClock)->rpo()['last_ok_hours'];
            $limit = DashboardStatus::staleHours();
            if ($age !== null && $age <= $limit) {
                return false;
            }
            // No backup yet: only once the password has been set up for longer than the limit.
            $since = $keys->setUpAt();
            if ($age === null && ($since === null || now()->getTimestamp() - $since <= $limit * 3600)) {
                return false;
            }
            $mailed = (int) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::MAILED_AT)->value('value');
            if ($mailed > 0 && now()->getTimestamp() - $mailed < self::EVERY_SECONDS) {
                return false;
            }
            if (!app(BackupNotifier::class)->stale($age, $settings['mode'])) {
                return false;
            }
            AdminConfig::setConfigValue('backup', self::MAILED_AT, GP247_STORE_ID_GLOBAL, (string) now()->getTimestamp(), 0);

            return true;
        } catch (\Throwable $e) {
            BackupLog::failure('Backup "too old" e-mail not checked: ' . class_basename($e));

            return false;
        }
    }
}
