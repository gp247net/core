<?php

namespace GP247\Core\Backup\Application;

use Carbon\CarbonInterface;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What the backup screen says about protection right now: the last verified archive, how old it
 * is, the warnings a non-technical owner needs to act on, and the job in progress (no secret of
 * its cursor ever leaves this class — only a phase and a percentage).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard
 * @aidlc-adr backup-admin_browser-beacon-scheduling
 */
final class DashboardStatus
{
    public const NOT_SET_UP = 'not_set_up';
    public const NO_BACKUP = 'no_backup';
    public const STALE = 'stale';
    public const LAST_BROKEN = 'last_broken';
    public const MAIL_OFF = 'mail_off';
    public const DISABLED = 'disabled';
    /** BK-6: the newest verified archive was not copied off-site. */
    public const OFFSITE_FAILED = 'offsite_failed';
    /** BK-6: a destination is set but cannot be used (library missing, host key changed). */
    public const OFFSITE_UNAVAILABLE = 'offsite_unavailable';
    /** Mod 20261110T164834: no verified full backup (or it is too old) while newer snapshots exist. */
    public const NO_FULL = 'no_full';
    public const FULL_STALE = 'full_stale';
    /** Mod 20261110T164834: the signing key is stored in clear — the key ring may have been replaced. */
    public const KEY_RING_UNTRUSTED = 'key_ring_untrusted';

    /**
     * @param KeyService|null    $keys
     * @param JobRepository|null $jobs
     */
    public function __construct(private ?KeyService $keys = null, private ?JobRepository $jobs = null)
    {
        $this->keys ??= new KeyService;
        $this->jobs ??= new JobRepository;
    }

    /**
     * @param CarbonInterface|null $now
     * @return array{last_ok: object|null, age_hours: int|null, full_age_hours: int|null, warnings: list<string>, job: array<string, mixed>|null}
     *         full_age_hours: age of the newest verified FULL backup (the only kind a whole-database restore uses).
     */
    public function summary(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive');
        // WHY not imported archives: they were made elsewhere (or earlier) — they say nothing about
        // how recent this site's own backups are.
        $own = fn () => $table->clone()->where('reason', '!=', Archive::REASON_IMPORT);
        $lastOk = $own()->where('status', Archive::STATUS_OK)->orderByDesc('created_at')->orderByDesc('id')->first();
        $latest = $own()->whereIn('status', [Archive::STATUS_OK, Archive::STATUS_BROKEN])->orderByDesc('created_at')->orderByDesc('id')->first();
        $age = $lastOk === null ? null : (int) floor(Carbon::parse($lastOk->created_at)->diffInMinutes($now, true) / 60);
        $lastFull = $own()->where('status', Archive::STATUS_OK)->where('kind', Archive::KIND_FULL)->orderByDesc('created_at')->orderByDesc('id')->first();
        $fullAge = $lastFull === null ? null : (int) floor(Carbon::parse($lastFull->created_at)->diffInMinutes($now, true) / 60);
        $warnings = self::warnings(
            BackupFeature::enabled(),
            $this->keys->isSetUp(),
            $age,
            ($latest->status ?? null) === Archive::STATUS_BROKEN,
            BackupNotifier::mailEnabled(),
            self::staleHours(),
            ($lastOk->offsite ?? null) === Archive::OFFSITE_FAILED,
            self::offsiteUnavailable(),
        );
        if ($warnings !== [] && in_array($warnings[0], [self::DISABLED, self::NOT_SET_UP], true)) {
            return ['last_ok' => $lastOk, 'age_hours' => $age, 'full_age_hours' => $fullAge, 'warnings' => $warnings, 'job' => $this->activeJob()];
        }
        if ($this->keys->problem() !== null) {
            array_unshift($warnings, self::KEY_RING_UNTRUSTED);
        }
        // A recent snapshot of one group must not hide that no (recent) full backup exists.
        if ($age !== null && $fullAge === null) {
            $warnings[] = self::NO_FULL;
        } elseif ($fullAge !== null && $fullAge >= self::staleHours() && !in_array(self::STALE, $warnings, true)) {
            $warnings[] = self::FULL_STALE;
        }

        return [
            'last_ok' => $lastOk,
            'age_hours' => $age,
            'full_age_hours' => $fullAge,
            'warnings' => $warnings,
            'job' => $this->activeJob(),
        ];
    }

    /**
     * @param bool     $enabled
     * @param bool     $setUp
     * @param int|null $ageHours   Age of the last verified archive; null = none.
     * @param bool     $lastBroken Whether the newest finished archive failed its check.
     * @param bool     $mail
     * @param int      $staleHours
     * @param bool     $offsiteFailed      The newest verified archive was not copied off-site.
     * @param bool     $offsiteUnavailable The destination is set but cannot be used.
     * @return list<string> Warning codes, most urgent first.
     */
    public static function warnings(bool $enabled, bool $setUp, ?int $ageHours, bool $lastBroken, bool $mail, int $staleHours, bool $offsiteFailed = false, bool $offsiteUnavailable = false): array
    {
        if (!$enabled) {
            return [self::DISABLED];
        }
        if (!$setUp) {
            return [self::NOT_SET_UP];
        }

        return array_values(array_filter([
            $ageHours === null ? self::NO_BACKUP : null,
            $ageHours !== null && $ageHours >= $staleHours ? self::STALE : null,
            $lastBroken ? self::LAST_BROKEN : null,
            $offsiteUnavailable ? self::OFFSITE_UNAVAILABLE : null,
            $offsiteFailed && !$offsiteUnavailable ? self::OFFSITE_FAILED : null,
            $mail ? null : self::MAIL_OFF,
        ]));
    }

    /**
     * @return bool A destination is set whose type is missing its library, or whose server key
     *              no longer matches the pinned one (last test).
     *
     * @aidlc-unit backup-storage
     * @aidlc-story US-backup-storage-sftp, US-backup-storage-s3-compatible
     */
    private static function offsiteUnavailable(): bool
    {
        try {
            $config = new DestinationConfig;
            $current = $config->current();
            if ($current === null) {
                return false;
            }
            $type = (new DestinationRegistry)->type($current['type']);
            $error = $config->report()?->error;

            return $type === null || !$type->available() || in_array($error, [DestinationFailure::HOST_KEY_CHANGED, DestinationFailure::UNAVAILABLE], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return int Hours after which the last backup counts as too old.
     */
    public static function staleHours(): int
    {
        return max(1, (int) config('gp247-config.backup.stale_hours', 48));
    }

    /**
     * @return array{id: int, type: string, archive_id: string|null, status: string, phase: string, percent: int|null}|null
     */
    public function activeJob(): ?array
    {
        $job = $this->jobs->nextActive();
        if ($job === null) {
            return null;
        }
        $phase = '';
        $percent = null;
        try {
            $cursor = $this->jobs->cursor($job);
            $phase = is_string($cursor['phase'] ?? null) ? $cursor['phase'] : '';
            $tables = $cursor['tables'] ?? null;
            if (is_array($tables) && $tables !== [] && is_int($cursor['ti'] ?? null)) {
                $percent = (int) min(99, floor(100 * $cursor['ti'] / count($tables)));
            }
        } catch (\Throwable $e) {
            // WHY: an unreadable cursor only loses the progress display, never the screen.
        }

        return [
            'id' => (int) $job->id,
            'type' => (string) $job->type,
            'archive_id' => $job->archive_id === null ? null : (string) $job->archive_id,
            'status' => (string) $job->status,
            'phase' => preg_replace('/[^a-z_]/', '', $phase) ?? '',
            'percent' => $percent,
        ];
    }
}
