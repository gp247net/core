<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Support\WriteActivity;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\BackupNotSetUp;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupScope;
use GP247\Core\Backup\Domain\FileGroup;
use GP247\Core\Backup\Infrastructure\Files\FileCollector;
use GP247\Core\Backup\Infrastructure\Format\Manifest;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Entry point for making a backup: checks, estimate, then a catalogue row (status "running")
 * and a backup job the {@see JobRunner} works through slice by slice. Making an archive needs
 * no password (only the site public key); a site without a backup password cannot make any.
 *
 * Only one writing job (backup / restore / import) is active at a time: creation locks the
 * `backup_enabled` config row, so two simultaneous requests are serialised and the second one
 * is told a backup is already running.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-quick-snapshot, US-backup-engine-selective-backup, US-backup-engine-full-backup
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class BackupService
{
    public const VIA_WEB = 'web';
    public const VIA_CLI = 'cli';
    /** Free space must cover the estimate with this margin (Q18). */
    private const SPACE_FACTOR = 1.2;

    private KeyService $keys;
    private LocalVault $vault;
    private ScopeResolver $resolver;
    private JobRepository $jobs;
    private ArchiveRepository $archives;

    /**
     * @param KeyService|null        $keys
     * @param LocalVault|null        $vault
     * @param ScopeResolver|null     $resolver
     * @param JobRepository|null     $jobs
     * @param ArchiveRepository|null $archives
     */
    public function __construct(
        ?KeyService $keys = null,
        ?LocalVault $vault = null,
        ?ScopeResolver $resolver = null,
        ?JobRepository $jobs = null,
        ?ArchiveRepository $archives = null,
    ) {
        $this->keys = $keys ?? new KeyService;
        $this->vault = $vault ?? app(LocalVault::class);
        $this->resolver = $resolver ?? app(ScopeResolver::class);
        $this->jobs = $jobs ?? new JobRepository;
        $this->archives = $archives ?? new ArchiveRepository;
    }

    /**
     * What a scope would back up, before anything starts.
     *
     * @param BackupScope $scope
     * @return array{tables: int, table_bytes: int, exact: bool, files: int, file_bytes: int, total_bytes: int,
     *               groups: list<string>, file_groups: list<string>, unassigned_raw: list<string>, warnings: list<string>}
     * @throws BackupRefused
     */
    public function estimate(BackupScope $scope): array
    {
        return $this->estimateResolved($this->resolver->resolve($scope));
    }

    /**
     * Queue a backup.
     *
     * @param BackupScope          $scope
     * @param string               $reason  manual | scheduled | pre_change:<operation>.
     * @param array<string, mixed> $options via ("web" | "cli"), confirm_large (bool), created_by (string).
     * @return array{archive_id: string, job_id: int, estimate: array}
     * @throws BackupRefused  When the request cannot start (reason code inside).
     * @throws BackupNotSetUp When no backup password was set up.
     */
    public function create(BackupScope $scope, string $reason = Archive::REASON_MANUAL, array $options = []): array
    {
        if (!BackupFeature::enabled()) {
            throw new BackupRefused(BackupRefused::FEATURE_DISABLED);
        }
        if (!Archive::validReason($reason)) {
            throw new BackupRefused(BackupRefused::REASON_INVALID);
        }
        $ring = $this->keys->keyRing();
        $resolved = $this->resolver->resolve($scope);
        $estimate = $this->estimateResolved($resolved);

        $via = ($options['via'] ?? self::VIA_WEB) === self::VIA_CLI ? self::VIA_CLI : self::VIA_WEB;
        $limit = (int) config('gp247-config.backup.web_limit_bytes', 0);
        if ($via === self::VIA_WEB && $limit > 0 && $estimate['total_bytes'] > $limit && empty($options['confirm_large'])) {
            throw new BackupRefused(BackupRefused::NEEDS_CONFIRMATION, 'larger than the web limit', ['estimate' => $estimate, 'limit' => $limit]);
        }
        $needed = (int) ceil($estimate['total_bytes'] * self::SPACE_FACTOR);
        $archiveId = (string) Str::ulid();
        $cursor = [
            'plan' => [
                'kind' => $scope->kind,
                'reason' => $reason,
                'via' => $via,
                'scope' => $scope->toArray(),
                'groups' => $resolved['groups'],
                'tables' => $resolved['tables'],
                'raw' => $resolved['raw'],
                'files' => $resolved['files'],
            ],
            'warnings' => array_values(array_unique($resolved['warnings'])),
        ];

        $jobId = DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($archiveId, $scope, $reason, $resolved, $ring, $options, $cursor, $needed) {
            // Serialises concurrent requests: the second one waits here, then sees the first job.
            // The vault is resolved under the same lock (its random name is created on first use).
            AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', BackupFeature::CONFIG_KEY)->lockForUpdate()->first();
            if ($this->jobs->hasActiveWriteJob()) {
                throw new BackupRefused(BackupRefused::ALREADY_RUNNING);
            }
            // US-backup-engine-write-activity-exclusion: an extension writing in bulk (data import).
            if (($other = WriteActivity::busy(WriteProbe::KEY)) !== null) {
                throw new BackupRefused(BackupRefused::BUSY_ELSEWHERE, $other['key'], ['activity' => $other['label']]);
            }
            $free = $this->vault->freeBytes();
            if ($free !== null && $free < $needed) {
                throw new BackupRefused(BackupRefused::DISK_SPACE, 'not enough free space', ['free' => $free, 'needed' => $needed]);
            }
            if ($free === null) {
                $cursor['warnings'][] = 'free_space_unknown';
            }
            $this->archives->create([
                'id' => $archiveId,
                'kind' => $scope->kind,
                'reason' => $reason,
                'scope' => json_encode($scope->toArray() + ['resolved_groups' => $resolved['groups'], 'file_groups' => array_column($resolved['files'], 'key')], JSON_UNESCAPED_SLASHES),
                'key_id' => $ring->keyId,
                'format' => Manifest::FORMAT_MAJOR,
                'created_by' => isset($options['created_by']) ? mb_substr((string) $options['created_by'], 0, 100) : null,
            ]);

            return $this->jobs->create(Archive::JOB_BACKUP, $archiveId, $cursor);
        });

        return ['archive_id' => $archiveId, 'job_id' => $jobId, 'estimate' => $estimate];
    }

    /**
     * The quick snapshot: one data group (Configuration by default), no files.
     *
     * @param string               $reason
     * @param string               $group
     * @param array<string, mixed> $options See create().
     * @return array{archive_id: string, job_id: int, estimate: array}
     */
    public function snapshot(string $reason = Archive::REASON_MANUAL, string $group = BackupScope::DEFAULT_SNAPSHOT_GROUP, array $options = []): array
    {
        return $this->create(BackupScope::snapshot($group), $reason, $options);
    }

    /**
     * Stop an unfinished archive and remove its files: nothing half-made stays in the catalogue.
     *
     * @param string $archiveId
     * @return void
     * @throws BackupRefused When the archive is unknown or already finished.
     */
    public function cancel(string $archiveId): void
    {
        // Only an archive still in progress changes state — checked in the UPDATE itself, so a
        // verification that ends at the same moment keeps its good archive.
        $cancelled = DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($archiveId) {
            if (!$this->archives->markCancelled($archiveId)) {
                return false;
            }
            foreach ($this->jobs->activeFor($archiveId) as $job) {
                $this->jobs->finish((int) $job->id, null, Archive::JOB_CANCELLED);
            }

            return true;
        });
        if (!$cancelled) {
            throw new BackupRefused(BackupRefused::NOT_FOUND);
        }
        if (!$this->vault->deleteArchive($archiveId)) {
            BackupLog::failure('Cancelled archive ' . $archiveId . ': its folder could not be removed.');
        }
    }

    /**
     * @param array $resolved From ScopeResolver::resolve().
     * @return array
     */
    private function estimateResolved(array $resolved): array
    {
        $sizes = [];
        $exact = true;
        try {
            foreach (DataGroupRegistry::currentSchemaTables() as $table) {
                $sizes[(string) $table['name']] = $table['size'] ?? null;
            }
        } catch (\Throwable $e) {
            $exact = false;
        }
        $tableBytes = 0;
        foreach ($resolved['tables'] as $table) {
            $size = $sizes[$table['real']] ?? null;
            if ($size === null) {
                $exact = false;
            }
            $tableBytes += (int) $size;
        }

        $files = 0;
        $fileBytes = 0;
        foreach ($resolved['files'] as $entry) {
            $collector = new FileCollector(new FileGroup($entry['key'], $entry['path'], $entry['exclude']), FileCollector::CHUNK_BYTES, $this->vault->root());
            $totals = $collector->totals();
            $files += $totals['count'];
            $fileBytes += $totals['bytes'];
        }

        return [
            'tables' => count($resolved['tables']),
            'table_bytes' => $tableBytes,
            'exact' => $exact,
            'files' => $files,
            'file_bytes' => $fileBytes,
            'total_bytes' => $tableBytes + $fileBytes,
            'groups' => $resolved['groups'],
            'file_groups' => array_column($resolved['files'], 'key'),
            'unassigned_raw' => $resolved['unassigned_raw'],
            'warnings' => $resolved['warnings'],
        ];
    }
}
