<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\BackupNotSetUp;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupScope;
use GP247\Core\Support\PreChange\PreChange;
use GP247\Core\Support\PreChange\PreChangeResult;
use Illuminate\Support\Facades\DB;

/**
 * The backup area's answer to the core "before change" signal (gp247-config.core.pre_change):
 * a snapshot of what the change may touch, before it runs.
 *
 *  - system.update    every data group but logs, no files (the migrations may touch any table)
 *  - extension.update `config` + the extension's own data group when it declares `dataTables`
 *                     (its folder is already copied aside by the extension update itself)
 *  - template.switch  `config` + `content` (banners, layout blocks, pages… — when front is installed)
 *
 * Feature off ⇒ go on, no question. No backup password ⇒ go on with a reminder. Command line ⇒ the
 * snapshot runs to its end. Web ⇒ one slice within the request budget; not finished ⇒ "stop: being
 * made, do it again in a moment" (the header beacon finishes it), and the next attempt within 10
 * minutes finds the verified snapshot and goes on without making another. Failure ⇒ stop with the
 * reason; the caller offers "go on without a snapshot" (its own skip switch).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-pre-change-snapshot
 * @aidlc-adr system-cli_pre-change-registry
 */
final class PreChangeGuard
{
    public const KEY = 'backup';
    private const REUSE_SECONDS = 600;

    /**
     * @param bool|null       $console Run to the end (command line); null = detect.
     * @param KeyService|null $keys
     */
    public function __construct(private ?bool $console = null, private ?KeyService $keys = null)
    {
    }

    /**
     * Entry registered in gp247-config.core.pre_change.
     *
     * @param PreChange $change
     * @return PreChangeResult
     */
    public static function check(PreChange $change): PreChangeResult
    {
        return (new self)($change);
    }

    /**
     * @param PreChange $change
     * @return PreChangeResult
     */
    public function __invoke(PreChange $change): PreChangeResult
    {
        if (!BackupFeature::enabled()) {
            return PreChangeResult::proceed();
        }
        if (!($this->keys ?? app(KeyService::class))->isSetUp()) {
            return PreChangeResult::proceed(gp247_language_render('admin.backup.pre_change.not_set_up'));
        }
        $scope = $this->scope($change);
        if ($scope === null) {
            return PreChangeResult::proceed();
        }
        $reason = self::reason($change);
        $recent = $this->recent($reason);
        if ($recent !== null) {
            return PreChangeResult::proceed(gp247_language_render('admin.backup.pre_change.done', ['id' => $recent]));
        }

        return $this->snapshot($scope, $reason);
    }

    /**
     * @param PreChange $change
     * @return BackupScope|null What to save, null for an operation the backup area does not know.
     */
    public function scope(PreChange $change): ?BackupScope
    {
        $known = array_keys((new DataGroupRegistry)->groups());
        $keep = fn (array $groups) => BackupScope::selective(array_values(array_intersect($groups, $known)) ?: [BackupScope::DEFAULT_SNAPSHOT_GROUP]);

        return match ($change->operation) {
            PreChange::SYSTEM_UPDATE => ScheduleSettings::scope(ScheduleSettings::KIND_DATA),
            PreChange::EXTENSION_UPDATE => $this->extensionScope((string) ($change->scope['type'] ?? ''), (string) ($change->scope['key'] ?? ''), $known, $keep),
            PreChange::TEMPLATE_SWITCH => $keep(['config', 'content']),
            default => null,
        };
    }

    /**
     * The configuration and the extension's own group; an extension that declares no tables has
     * them among the "Other" tables, so every data group and the "Other" tables are taken instead —
     * a snapshot of the configuration alone would say "saved" without the extension's data
     * (mod 20261110T164834, measured: no local plugin declares `dataTables`).
     *
     * @param string       $type
     * @param string       $key
     * @param list<string> $known Group keys of the registry.
     * @param callable     $keep
     * @return BackupScope
     */
    private function extensionScope(string $type, string $key, array $known, callable $keep): BackupScope
    {
        $group = self::extensionGroup($type, $key);
        if (in_array($group, $known, true)) {
            return $keep(['config', $group]);
        }
        $data = ScheduleSettings::scope(ScheduleSettings::KIND_DATA);

        return BackupScope::selective($data->groups, [], true);
    }

    /**
     * Data group of an extension: the `group` its gp247.json declares under `dataTables`, else
     * the registry's default key `ext.<type>.<key>` (DataGroupRegistry::declarations()).
     *
     * @param string $type Plugins | Templates (as the extension update passes it).
     * @param string $key
     * @return string
     */
    private static function extensionGroup(string $type, string $key): string
    {
        $type = strcasecmp($type, 'Templates') === 0 ? 'Templates' : 'Plugins';
        try {
            $declared = DataGroupRegistry::extensionManifests()[$type . '/' . $key]['dataTables']['group'] ?? null;
        } catch (\Throwable $e) {
            $declared = null;
        }

        return is_string($declared) && $declared !== '' ? $declared : 'ext.' . strtolower($type) . '.' . $key;
    }

    /**
     * `pre_change:<operation>[:<type>/<key>][:<from>:<to>]` within the catalogue's reason format,
     * so the archive list says which change it was made for.
     *
     * @param PreChange $change
     * @return string
     */
    public static function reason(PreChange $change): string
    {
        $clean = fn (?string $v) => (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $v);
        $parts = [$clean($change->operation)];
        if (isset($change->scope['key'])) {
            $parts[] = $clean((string) ($change->scope['type'] ?? '')) . '/' . $clean((string) $change->scope['key']);
        }
        if ($change->from !== null || $change->to !== null) {
            $parts[] = $clean($change->from);
            $parts[] = $clean($change->to);
        }

        return mb_substr(Archive::REASON_PRE_CHANGE . implode(':', $parts), 0, 80 + strlen(Archive::REASON_PRE_CHANGE) - 1);
    }

    /**
     * @param string $reason
     * @return string|null Id of a verified snapshot for the same change made in the last minutes.
     */
    private function recent(string $reason): ?string
    {
        $id = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
            ->where('reason', $reason)->where('status', Archive::STATUS_OK)
            ->where('created_at', '>=', now()->subSeconds(self::REUSE_SECONDS))
            ->orderByDesc('created_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @param BackupScope $scope
     * @param string      $reason
     * @return PreChangeResult
     */
    private function snapshot(BackupScope $scope, string $reason): PreChangeResult
    {
        $console = $this->console ?? app()->runningInConsole();
        try {
            $created = (new BackupService)->create($scope, $reason, [
                'via' => $console ? BackupService::VIA_CLI : BackupService::VIA_WEB,
                'confirm_large' => true,
                'created_by' => 'pre_change',
            ]);
        } catch (BackupRefused $e) {
            $running = $this->runningFor($reason);
            if ($e->reason === BackupRefused::ALREADY_RUNNING && $running !== null) {
                return $this->drive($running['job_id'], $running['archive_id'], $console);
            }

            return PreChangeResult::stop(gp247_language_render('admin.backup.pre_change.failed', ['reason' => $e->reason]));
        } catch (BackupNotSetUp $e) {
            return PreChangeResult::proceed(gp247_language_render('admin.backup.pre_change.not_set_up'));
        }
        BackupAudit::record('create', BackupAudit::OK, ['archive_id' => $created['archive_id'], 'job_id' => $created['job_id'], 'reason' => mb_substr($reason, 0, 60)]);

        return $this->drive((int) $created['job_id'], (string) $created['archive_id'], $console);
    }

    /**
     * @param int    $jobId
     * @param string $archiveId
     * @param bool   $console
     * @return PreChangeResult
     */
    private function drive(int $jobId, string $archiveId, bool $console): PreChangeResult
    {
        $runner = new JobRunner;
        // WHY half a slice on the web: the change itself (download, migrations, publish) still has
        // to run in the same request after this answer, within the same max_execution_time.
        $console ? $runner->runToEnd($jobId) : $runner->runSlice($jobId, max(1.0, SliceBudget::seconds() / 2));
        $status = (string) DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')->where('id', $archiveId)->value('status');

        return match ($status) {
            Archive::STATUS_OK => PreChangeResult::proceed(gp247_language_render('admin.backup.pre_change.done', ['id' => $archiveId])),
            Archive::STATUS_RUNNING, Archive::STATUS_VERIFYING => PreChangeResult::stop(gp247_language_render('admin.backup.pre_change.running'), (string) $jobId),
            default => PreChangeResult::stop(gp247_language_render('admin.backup.pre_change.failed', ['reason' => $status ?: 'unknown'])),
        };
    }

    /**
     * @param string $reason
     * @return array{job_id: int, archive_id: string}|null The snapshot of this change still being made.
     */
    private function runningFor(string $reason): ?array
    {
        $row = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
            ->where('reason', $reason)->whereIn('status', [Archive::STATUS_RUNNING, Archive::STATUS_VERIFYING])
            ->orderByDesc('created_at')->first();
        if ($row === null) {
            return null;
        }
        $jobs = (new \GP247\Core\Backup\Infrastructure\Store\JobRepository)->activeFor((string) $row->id);

        return $jobs === [] ? null : ['job_id' => (int) $jobs[0]->id, 'archive_id' => (string) $row->id];
    }
}
