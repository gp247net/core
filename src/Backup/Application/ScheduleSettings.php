<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\BackupScope;
use GP247\Core\Models\AdminConfig;

/**
 * When scheduled backups run and what they hold (admin_config, code "backup", global store).
 * A missing key means the default, so a site updated from an older core gets the schedule
 * without any data migration: "when an admin visits", every 24 hours, a full backup without
 * logs — it still runs only once a backup password is set up.
 *
 *  - off    no scheduled backup
 *  - visit  run in the background while an administrator has the admin open (no cron needed)
 *  - cron   registered in Laravel's scheduler (needs `schedule:run` every minute)
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
final class ScheduleSettings
{
    public const MODE = 'backup_schedule_mode';
    public const HOURS = 'backup_schedule_hours';
    public const AT = 'backup_schedule_at';
    public const KIND = 'backup_schedule_kind';

    public const MODE_OFF = 'off';
    public const MODE_VISIT = 'visit';
    public const MODE_CRON = 'cron';
    public const MODES = [self::MODE_OFF, self::MODE_VISIT, self::MODE_CRON];

    /** @var list<int> Cycles offered on the screen (hours). */
    public const HOURS_ALLOWED = [6, 12, 24, 48, 168];

    /** Whole site (database without logs + upload, extension, .env and lock files). */
    public const KIND_FULL = 'full';
    /** Database only (every data group but logs), no files. */
    public const KIND_DATA = 'data';
    public const KINDS = [self::KIND_FULL, self::KIND_DATA];

    private const DEFAULTS = [self::MODE => self::MODE_VISIT, self::HOURS => 24, self::AT => '03:00', self::KIND => self::KIND_FULL];

    /**
     * @return array{mode: string, hours: int, at: string, kind: string}
     */
    public static function read(): array
    {
        $rows = [];
        try {
            $rows = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
                ->whereIn('key', [self::MODE, self::HOURS, self::AT, self::KIND])->pluck('value', 'key')->all();
        } catch (\Throwable $e) {
            // Database not reachable (CLI before install): defaults, never break the caller.
        }
        $mode = (string) ($rows[self::MODE] ?? self::DEFAULTS[self::MODE]);
        $hours = (int) ($rows[self::HOURS] ?? self::DEFAULTS[self::HOURS]);
        $at = (string) ($rows[self::AT] ?? self::DEFAULTS[self::AT]);
        $kind = (string) ($rows[self::KIND] ?? self::DEFAULTS[self::KIND]);

        return [
            'mode' => in_array($mode, self::MODES, true) ? $mode : self::DEFAULTS[self::MODE],
            'hours' => in_array($hours, self::HOURS_ALLOWED, true) ? $hours : self::DEFAULTS[self::HOURS],
            'at' => self::validTime($at) ? $at : self::DEFAULTS[self::AT],
            'kind' => in_array($kind, self::KINDS, true) ? $kind : self::DEFAULTS[self::KIND],
        ];
    }

    /**
     * @param string $mode
     * @param int    $hours
     * @param string $at
     * @param string $kind
     * @return list<string> Invalid field names (empty = saved).
     */
    public static function save(string $mode, int $hours, string $at, string $kind): array
    {
        $invalid = array_keys(array_filter([
            'mode' => !in_array($mode, self::MODES, true),
            'hours' => !in_array($hours, self::HOURS_ALLOWED, true),
            'at' => !self::validTime($at),
            'kind' => !in_array($kind, self::KINDS, true),
        ]));
        if ($invalid !== []) {
            return $invalid;
        }
        foreach ([self::MODE => $mode, self::HOURS => (string) $hours, self::AT => $at, self::KIND => $kind] as $key => $value) {
            AdminConfig::setConfigValue('backup', $key, GP247_STORE_ID_GLOBAL, $value, 0);
        }

        return [];
    }

    /**
     * @param string $kind
     * @return BackupScope What a scheduled backup of this kind holds.
     */
    public static function scope(string $kind): BackupScope
    {
        if ($kind !== self::KIND_DATA) {
            return BackupScope::full(true);
        }
        $groups = array_values(array_filter(array_keys((new DataGroupRegistry)->groups()), fn ($key) => $key !== 'logs'));

        return BackupScope::selective($groups);
    }

    /**
     * @param string $value
     * @return bool A 24-hour "HH:MM".
     */
    private static function validTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $value) === 1;
    }
}
