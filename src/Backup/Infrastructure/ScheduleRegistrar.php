<?php

namespace GP247\Core\Backup\Infrastructure;

use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\Backup\Application\ScheduleSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * "Cron" mode of the backup schedule: `gp247:backup-run --scheduled` in Laravel's scheduler at the
 * hour set on the backup settings screen, never two at once. Registered only when the feature is
 * on and the mode is "cron"; the ScheduledTasks plugin, when installed, lists it like every other
 * task (the core does not depend on that plugin). Needs `php artisan schedule:run` every minute —
 * the settings screen shows how.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
final class ScheduleRegistrar
{
    public const COMMAND = 'gp247:backup-run --scheduled';

    /**
     * @param Schedule $schedule
     * @return Event|null The registered event, null when not in cron mode.
     */
    public static function register(Schedule $schedule): ?Event
    {
        try {
            $settings = ScheduleSettings::read();
            if ($settings['mode'] !== ScheduleSettings::MODE_CRON || !BackupFeature::enabled()) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        [$hour, $minute] = array_map('intval', explode(':', $settings['at']));
        $event = $schedule->command(self::COMMAND)
            ->description('GP247 backup (scheduled)')
            ->withoutOverlapping(720);

        return match ($settings['hours']) {
            6, 12 => $event->cron(sprintf('%d %d-23/%d * * *', $minute, $hour % $settings['hours'], $settings['hours'])),
            48 => $event->cron(sprintf('%d %d */2 * *', $minute, $hour)),
            168 => $event->weeklyOn(1, $settings['at']),
            default => $event->dailyAt($settings['at']),
        };
    }
}
