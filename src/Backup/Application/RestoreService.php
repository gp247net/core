<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Support\WriteActivity;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\RestorePlan;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Database\SchemaInspector;
use GP247\Core\Backup\Infrastructure\Database\TableSwapper;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Site\MaintenanceGuard;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Backup\Infrastructure\Store\RestoreLedger;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;

/**
 * Entry point of the restore levels R0–R2: preview, start (a sliced "restore" job run by the
 * JobRunner), undo of the last table-replacing restore, cancel.
 *
 * Permission checks, re-authentication, rate limiting of password attempts and the screen
 * belong to backup-admin (BK-4); every method here acts on the whole site, so callers must
 * restrict it to the site owner (root admin), never to a store admin.
 *
 * The backup password is used when the job starts, to unwrap the archive's data key; the job
 * keeps only that data key (encrypted with the site key, gone after one hour or when the job
 * ends) — never the password nor the site private key.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database, US-backup-restore-version-compatibility, US-backup-restore-cli-restore, US-backup-restore-post-incident-mode
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class RestoreService
{
    /** An undo holds a restore job as its lock for at most this long. */
    private const UNDO_LEASE_SECONDS = 600;

    private KeyService $keys;
    private LocalVault $vault;
    private ArchiveRepository $archives;
    private JobRepository $jobs;
    private RestoreLedger $ledger;
    private ?DataGroupRegistry $registry;

    /**
     * @param KeyService|null        $keys
     * @param LocalVault|null        $vault
     * @param ArchiveRepository|null $archives
     * @param JobRepository|null     $jobs
     * @param RestoreLedger|null     $ledger
     * @param DataGroupRegistry|null $registry
     */
    public function __construct(
        ?KeyService $keys = null,
        ?LocalVault $vault = null,
        ?ArchiveRepository $archives = null,
        ?JobRepository $jobs = null,
        ?RestoreLedger $ledger = null,
        ?DataGroupRegistry $registry = null,
    ) {
        $this->keys = $keys ?? new KeyService;
        $this->vault = $vault ?? app(LocalVault::class);
        $this->archives = $archives ?? new ArchiveRepository;
        $this->jobs = $jobs ?? new JobRepository;
        $this->ledger = $ledger ?? new RestoreLedger;
        $this->registry = $registry;
    }

    /**
     * What a restore would do, before anything is applied.
     *
     * @param RestorePlan          $plan
     * @param BackupSecret         $secret   Backup password or recovery key.
     * @param array<string, mixed> $operator ['id', 'username'] of the admin restoring.
     * @param array<string, mixed> $options  accept_unverified (bool), post_incident (bool) — see start().
     * @return array<string, mixed> See RestorePlanner::inspect(); undelete adds "undelete" (candidates);
     *                              BK-5 adds "origin" and "post_incident".
     * @throws RestoreRefused
     */
    public function preview(RestorePlan $plan, #[\SensitiveParameter] BackupSecret $secret, array $operator = [], array $options = []): array
    {
        [$archive, $dir] = $this->source($plan);
        $origin = $this->archiveOrigin($archive, $dir);
        $reader = ArchiveReader::open($dir, $secret, $this->keys, $origin['origin']);

        return $this->withBk5Notes($this->inspect($plan, $reader, $archive, $operator, $origin['origin'])['preview'], $plan, $origin, $options);
    }

    /**
     * Queue a restore job after the same checks as the preview.
     *
     * @param RestorePlan          $plan
     * @param BackupSecret         $secret
     * @param array<string, mixed> $options operator (['id', 'username']), created_by;
     *                                      accept_unverified: the person confirmed restoring an archive whose origin cannot be verified;
     *                                      post_incident: full level only — sessions, tokens, passwords after the tables are in place;
     *                                      accept_column_loss: the person confirmed the data of columns the site no longer has is lost;
     *                                      via ("web" | "cli") + administrator (bool): an unverified archive on the web needs an Administrator.
     * @return array{job_id: int, preview: array, maintenance_secret: string|null}
     *         maintenance_secret: bypass secret of the maintenance mode of a full restore — the admin
     *         screen turns it into the cookie of the person restoring (BK-4), never logs it.
     * @throws RestoreRefused When the preview refuses, or another job that writes is active.
     */
    public function start(RestorePlan $plan, #[\SensitiveParameter] BackupSecret $secret, array $options = []): array
    {
        (new ShadowJanitor($this->jobs, $this->ledger, $this->registry))->sweep();
        [$archive, $dir] = $this->source($plan);
        $origin = $this->archiveOrigin($archive, $dir);
        $dek = ArchiveReader::dataKey($dir, $secret, $this->keys, $origin['origin']);
        $reader = ArchiveReader::openWithDataKey($dir, $dek, $origin['origin'] === ArchiveReader::ORIGIN_SITE ? $this->keys->signingKey() : null, $origin['origin']);
        $inspected = $this->inspect($plan, $reader, $archive, (array) ($options['operator'] ?? []), $origin['origin']);
        $preview = $this->withBk5Notes($inspected['preview'], $plan, $origin, $options);
        if ($preview['refusals'] !== []) {
            throw new RestoreRefused($preview['refusals'][0], '', ['preview' => $preview]);
        }
        $resolved = $inspected['resolved'];
        $header = $reader->header();
        $maintenanceSecret = $plan->level === RestorePlan::FULL ? MaintenanceGuard::newSecret() : null;
        $cursor = [
            'plan' => $plan->toArray(),
            'tables' => array_map(fn ($t) => ['name' => $t['name'], 'real' => $t['real'], 'columns' => $t['columns'], 'rows' => $t['rows']], $resolved['tables']),
            'children' => $plan->level === RestorePlan::UNDELETE ? $this->planner()->children($plan->tables[0], $reader) : [],
            'skipped' => ['absent' => $resolved['absent'], 'not_in_archive' => $resolved['not_in_archive'], 'excluded' => $resolved['excluded']],
            'compatibility' => $preview['compatibility']['status'],
            'dek' => base64_encode($dek),
            'dek_wrapped' => $header['dek'],
            'key_id' => $header['key_id'],
            'started' => now()->getTimestamp(),
            'phase' => 'verify',
            'step' => 0,
            'ti' => 0,
            'seg' => 0,
            'loaded' => 0,
            'created' => false,
            'expected' => [],
            'maintenance_secret' => $maintenanceSecret,
            'origin' => $origin['origin'],
            'post_incident' => !empty($options['post_incident']),
            'created_by' => isset($options['created_by']) ? mb_substr((string) $options['created_by'], 0, 100) : null,
        ];

        $jobId = $this->locked(function () use ($plan, $cursor) {
            return $this->jobs->create(Archive::JOB_RESTORE, $plan->archiveId, $cursor);
        });

        return ['job_id' => $jobId, 'preview' => $preview, 'maintenance_secret' => $maintenanceSecret];
    }

    /**
     * Put back the tables the last table-replacing restore replaced (its old copies), within the
     * undo window. The backup feature's rows of admin_config stay as they are NOW (a password
     * changed since the restore keeps working). A restore job is held as the lock meanwhile.
     *
     * @return array{tables: list<string>} Real names put back.
     * @throws RestoreRefused (nothing_to_undo | undo_expired | already_running)
     */
    public function undo(): array
    {
        $point = $this->ledger->undoPoint();
        $tables = array_values(array_map('strval', (array) ($point['tables'] ?? [])));
        if ($tables === []) {
            throw new RestoreRefused(RestoreRefused::NOTHING_TO_UNDO);
        }
        if (!is_string($point['undo_until'] ?? null) || now()->gte($point['undo_until'])) {
            throw new RestoreRefused(RestoreRefused::UNDO_EXPIRED);
        }
        $schema = new SchemaInspector(DB::connection(GP247_DB_CONNECTION));
        $swapper = new TableSwapper($schema);
        if ($swapper->state($tables) !== 'swapped') {
            throw new RestoreRefused(RestoreRefused::NOTHING_TO_UNDO, 'copies missing');
        }
        $owner = bin2hex(random_bytes(16));
        $jobId = $this->locked(function () use ($tables, $point, $owner) {
            $id = $this->jobs->create(Archive::JOB_RESTORE, $point['archive_id'] ?? null, ['phase' => RestoreStep::UNDO_PHASE, 'tables' => $tables]);
            $this->jobs->acquire($id, $owner, now()->addSeconds(self::UNDO_LEASE_SECONDS));

            return $id;
        });

        try {
            foreach ($tables as $table) {
                if (ConfigRestoreRules::isConfigTable($table)) {
                    ConfigRestoreRules::keepBackupRows($schema->db(), $table, TableSwapper::name($table, TableSwapper::OLD));
                }
            }
            // WHY no shadow should be left: revert() renames the restored data to T__bkr.
            $swapper->revert($tables);
            foreach ($tables as $table) {
                // The triggers went along with the restored data: back to the table now in place.
                // A copy holding a trigger that could not be moved stays for a person to look at.
                if ($schema->moveTriggers(TableSwapper::name($table, TableSwapper::SHADOW), $table) !== []) {
                    BackupLog::failure('Undo: a trigger of ' . $table . ' could not be moved back; its copy is kept.');
                    continue;
                }
                $swapper->drop(TableSwapper::name($table, TableSwapper::SHADOW));
            }
        } catch (\Throwable $e) {
            // The copies are left as they are for a person to look at, never cleaned up automatically.
            $this->ledger->quarantine($tables);
            $this->jobs->finish($jobId, $owner, Archive::JOB_FAILED, 'restore: undo_failed');
            throw $e;
        }
        ConfigRestoreRules::refreshCaches();
        // Written after the revert: when admin_config was put back, its ledger rows came from the copy.
        $this->ledger->recordUndoPoint(null);
        $this->ledger->recordLast(['status' => 'undone', 'tables' => $tables, 'undone_at' => now()->toIso8601String()] + $point);
        $this->jobs->finish($jobId, $owner, Archive::JOB_DONE);

        return ['tables' => $tables];
    }

    /**
     * Stop a restore that has not replaced anything yet. Taken through the job lease, so it never
     * races a running slice: while one runs, the answer is "already running" (try again).
     *
     * @param int $jobId
     * @return void
     * @throws RestoreRefused (not_found | not_cancellable | already_running)
     */
    public function cancel(int $jobId): void
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->type !== Archive::JOB_RESTORE || !in_array($job->status, Archive::JOB_ACTIVE, true)) {
            throw new RestoreRefused(RestoreRefused::NOT_FOUND);
        }
        $owner = bin2hex(random_bytes(16));
        if (!$this->jobs->acquire($jobId, $owner, now()->addSeconds(60))) {
            throw new RestoreRefused(RestoreRefused::ALREADY_RUNNING, 'a slice is running');
        }
        try {
            $cursor = $this->jobs->cursor($this->jobs->find($jobId));
        } catch (\RuntimeException $e) {
            $cursor = [];
        }
        if (!in_array($cursor['phase'] ?? null, RestoreStep::CANCELLABLE_PHASES, true)) {
            $this->jobs->release($jobId, $owner);
            throw new RestoreRefused(RestoreRefused::NOT_CANCELLABLE, (string) ($cursor['phase'] ?? ''));
        }
        $this->jobs->finish($jobId, $owner, Archive::JOB_CANCELLED, 'restore: cancelled');
        (new RestoreStep($this->jobs, $this->keys, $this->vault, $this->ledger))->abort($job, $cursor, 'cancelled');
    }

    /**
     * @return array<string, mixed>|null Result of the last restore (done / done_with_errors / failed / undone…), no secrets.
     */
    public function last(): ?array
    {
        return $this->ledger->last();
    }

    /**
     * @return array<string, mixed>|null Tables the last table-replacing restore can still put back, and until when.
     */
    public function undoPoint(): ?array
    {
        return $this->ledger->undoPoint();
    }

    /**
     * @param RestorePlan $plan
     * @return array{0: object, 1: string} Catalogue row, archive directory.
     * @throws RestoreRefused
     */
    private function source(RestorePlan $plan): array
    {
        if (!BackupFeature::enabled()) {
            throw new RestoreRefused(RestoreRefused::FEATURE_DISABLED);
        }
        $archive = $this->archives->find($plan->archiveId);
        if ($archive === null) {
            throw new RestoreRefused(RestoreRefused::NOT_FOUND);
        }
        RestorePlanner::assertSource($plan, $archive);

        return [$archive, $this->vault->archiveDir($plan->archiveId)];
    }

    /**
     * How the archive's origin can be checked here. A catalogue archive of this site whose
     * signature cannot be checked because the signing key is unreadable (the site lost the key it
     * was encrypted with) is treated as "unverified"; a signature that does NOT match on an
     * archive of this site means the file was changed — refused, never downgraded.
     *
     * @param object $archive Catalogue row.
     * @param string $dir
     * @return array{origin: string, reason: string|null}
     * @throws ArchiveRejected signature_mismatch
     *
     * @aidlc-story US-backup-restore-import-archive, US-backup-restore-cli-restore
     */
    public function archiveOrigin(object $archive, string $dir): array
    {
        return ArchiveOrigin::of($archive, $dir, $this->keys);
    }

    /**
     * Origin warning, post-incident notes and the refusals they bring (BK-5).
     *
     * @param array<string, mixed>                       $preview
     * @param RestorePlan                                $plan
     * @param array{origin: string, reason: string|null} $origin
     * @param array<string, mixed>                       $options
     * @return array<string, mixed>
     *
     * @aidlc-story US-backup-restore-import-archive, US-backup-restore-post-incident-mode
     */
    private function withBk5Notes(array $preview, RestorePlan $plan, array $origin, array $options): array
    {
        $postIncident = !empty($options['post_incident']);
        $preview['origin'] = $origin;
        $preview['post_incident'] = $postIncident ? [
            'steps' => ['sessions', 'remember', 'tokens', 'password'],
            'tasks' => PostIncidentChecklist::TASKS,
        ] : null;
        if ($origin['origin'] === ArchiveReader::ORIGIN_UNVERIFIED && empty($options['accept_unverified'])) {
            $preview['refusals'][] = RestoreRefused::ORIGIN_UNVERIFIED;
        }
        if ($postIncident && $plan->level !== RestorePlan::FULL) {
            $preview['refusals'][] = RestoreRefused::POST_INCIDENT_LEVEL;
        }
        // Mod 20261110T164834 (D1): on the web, the restore right alone does not let someone restore
        // an archive this site did not sign (one made with their own password could replace accounts).
        if ($origin['origin'] === ArchiveReader::ORIGIN_UNVERIFIED && ($options['via'] ?? null) !== 'cli' && empty($options['administrator'])) {
            $preview['refusals'][] = RestoreRefused::UNVERIFIED_ADMIN_ONLY;
        }
        // Mod 20261110T164834 (A1): columns of the archive the site no longer has lose their data.
        $preview['lost_columns'] = [];
        foreach ((array) ($preview['tables'] ?? []) as $table) {
            if (!empty($table['columns_ignored'])) {
                $preview['lost_columns'][(string) $table['name']] = array_values($table['columns_ignored']);
            }
        }
        if ($preview['lost_columns'] !== [] && empty($options['accept_column_loss'])) {
            $preview['refusals'][] = RestoreRefused::COLUMNS_LOST;
        }
        $preview['refusals'] = array_values(array_unique($preview['refusals']));

        return $preview;
    }

    /**
     * @param RestorePlan          $plan
     * @param ArchiveReader        $reader
     * @param object               $archive
     * @param array<string, mixed> $operator
     * @param string               $origin   ArchiveReader::ORIGIN_*.
     * @return array{preview: array, resolved: array}
     */
    private function inspect(RestorePlan $plan, ArchiveReader $reader, object $archive, array $operator, string $origin = ArchiveReader::ORIGIN_SITE): array
    {
        $planner = $this->planner()->forOrigin($origin);
        $inspected = $planner->inspect($plan, $reader, $archive, $operator);
        if ($plan->level === RestorePlan::UNDELETE) {
            $inspected['preview']['undelete'] = (new UndeleteService($this->schema()))->candidates($reader, $inspected['resolved']['tables'][0]) + [
                'children' => array_column($planner->children($plan->tables[0], $reader), 'name'),
            ];
        }

        return $inspected;
    }

    /**
     * Run under the lock BackupService::create() uses: one job that writes at a time.
     *
     * @param callable $create Creates the job; returns its id.
     * @return int
     * @throws RestoreRefused (already_running)
     */
    private function locked(callable $create): int
    {
        return DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($create) {
            AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', BackupFeature::CONFIG_KEY)->lockForUpdate()->first();
            if ($this->jobs->hasActiveWriteJob()) {
                throw new RestoreRefused(RestoreRefused::ALREADY_RUNNING);
            }
            // US-backup-engine-write-activity-exclusion: an extension writing in bulk (data import).
            if (($other = WriteActivity::busy(WriteProbe::KEY)) !== null) {
                throw new RestoreRefused(RestoreRefused::BUSY_ELSEWHERE, $other['key'], ['activity' => $other['label']]);
            }

            return $create();
        });
    }

    /**
     * @return RestorePlanner
     */
    private function planner(): RestorePlanner
    {
        return new RestorePlanner($this->schema(), $this->registry);
    }

    /**
     * @return SchemaInspector
     */
    private function schema(): SchemaInspector
    {
        return new SchemaInspector(DB::connection(GP247_DB_CONNECTION));
    }
}
