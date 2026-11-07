<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Whether a scheduled backup is due, and the recovery point the site can really count on.
 * A manual backup does not move the schedule (simple and predictable). Each attempt is recorded
 * (`backup_schedule_tried_at`), and a refused or failed attempt is not repeated for an hour, so
 * a full disk does not turn every admin page view into a new attempt.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-schedule
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
final class ScheduleClock
{
    public const TRIED_AT = 'backup_schedule_tried_at';
    private const RETRY_SECONDS = 3600;
    private const CACHE_KEY = 'gp247-backup-schedule-due';
    private const CACHE_SECONDS = 60;

    /**
     * @param JobRepository|null $jobs
     * @param KeyService|null    $keys
     */
    public function __construct(private ?JobRepository $jobs = null, private ?KeyService $keys = null)
    {
        $this->jobs ??= new JobRepository;
    }

    /**
     * @param bool $fresh Skip the one-minute memo (the tick that is about to create the job).
     * @return bool A scheduled backup should start now ("when an admin visits" mode).
     */
    public function due(bool $fresh = false): bool
    {
        if (!$fresh) {
            return (bool) Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->due(true) ? 1 : 0);
        }
        try {
            $settings = ScheduleSettings::read();
            if ($settings['mode'] !== ScheduleSettings::MODE_VISIT || !BackupFeature::enabled() || !$this->keys()->isSetUp()) {
                return false;
            }
            if ($this->jobs->hasActiveWriteJob()) {
                return false;
            }
            $tried = $this->triedAt();
            if ($tried !== null && $tried->diffInSeconds(now(), true) < self::RETRY_SECONDS) {
                return false;
            }
            $last = $this->lastScheduled();

            return $last === null || $last->diffInMinutes(now(), true) >= $settings['hours'] * 60;
        } catch (\Throwable $e) {
            // Never break an admin page because of the schedule.
            return false;
        }
    }

    /**
     * Record an attempt (before creating the job) and drop the memo.
     *
     * @return void
     */
    public function markTried(): void
    {
        AdminConfig::setConfigValue('backup', self::TRIED_AT, GP247_STORE_ID_GLOBAL, (string) now()->getTimestamp(), 0);
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return void
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return Carbon|null When the newest scheduled archive was started (any outcome).
     */
    public function lastScheduled(): ?Carbon
    {
        $at = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
            ->where('reason', Archive::REASON_SCHEDULED)->whereIn('status', [Archive::STATUS_OK, Archive::STATUS_BROKEN, Archive::STATUS_RUNNING, Archive::STATUS_VERIFYING])
            ->max('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * @return bool Cron mode is set but no scheduled backup appeared for two cycles (no `schedule:run`).
     */
    public function cronMissed(): bool
    {
        $settings = ScheduleSettings::read();
        if ($settings['mode'] !== ScheduleSettings::MODE_CRON) {
            return false;
        }
        $limit = $settings['hours'] * 2 * 60;
        $last = $this->lastScheduled();
        if ($last !== null) {
            return $last->diffInMinutes(now(), true) > $limit;
        }
        $since = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', ScheduleSettings::MODE)->value('updated_at');

        return $since !== null && Carbon::parse($since)->diffInMinutes(now(), true) > $limit;
    }

    /**
     * What the backup screen says about the recovery point.
     *
     * @return array{mode: string, hours: int, at: string, kind: string, last_ok_hours: int|null, cron_missed: bool}
     */
    public function rpo(): array
    {
        $settings = ScheduleSettings::read();
        $lastOk = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
            ->where('status', Archive::STATUS_OK)->where('reason', '!=', Archive::REASON_IMPORT)->max('created_at');

        return $settings + [
            'last_ok_hours' => $lastOk === null ? null : (int) floor(Carbon::parse($lastOk)->diffInMinutes(now(), true) / 60),
            'cron_missed' => $this->cronMissed(),
        ];
    }

    /**
     * @return Carbon|null
     */
    private function triedAt(): ?Carbon
    {
        $value = (int) AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', self::TRIED_AT)->value('value');

        return $value > 0 ? Carbon::createFromTimestamp($value) : null;
    }

    /**
     * @return KeyService
     */
    private function keys(): KeyService
    {
        return $this->keys ??= app(KeyService::class);
    }
}
