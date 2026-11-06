<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\CompatibilityGate;
use GP247\Core\Backup\Domain\RestorePlan;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Database\SchemaInspector;
use GP247\Core\Backup\Infrastructure\Database\ShadowLoader;
use GP247\Core\Backup\Infrastructure\Database\TableSwapper;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Format\CanonicalJson;
use GP247\Core\Backup\Infrastructure\Site\MaintenanceGuard;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Backup\Infrastructure\Store\RestoreLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One slice of a restore job (logical design backup-restore §4). Phases, each resumable:
 *
 *   verify      the whole archive is read back (part hashes, frames, segments) BEFORE any DDL —
 *               there is no way to skip it;
 *   maintenance (full) Laravel maintenance mode on, bypass secret for the person restoring;
 *   load        each table into its shadow T__bkr, segment by segment, row count = manifest;
 *               "remember me" tokens emptied; the data key is dropped once nothing more is read;
 *   swap        admin_config shadow takes the site's CURRENT backup rows, shadow counts checked,
 *               then one atomic rename of the whole set (T → T__bko, T__bkr → T);
 *   refresh     configuration memo / caches cleared;
 *   update      (older archive) gp247:update in-process — run at most once, its failure reported;
 *   apply       (undelete) missing rows + child rows in one transaction, no renaming;
 *   finish      maintenance off, undo point + result recorded, job done; then, in post-incident
 *               mode (full level), sessions end, tokens are revoked, new passwords are required —
 *               last, after the data update, so ending the restoring admin's own session stops nothing.
 *
 * Before the rename nothing on the site has changed: any failure drops the shadows. The rename
 * is atomic, so once it happened the restore is applied: a later failure (caches, data update)
 * keeps the restored tables, keeps the old copies as the undo point and reports the error.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database, US-backup-restore-version-compatibility
 * @aidlc-adr backup-restore_shadow-table-swap, backup-engine_sliced-job-runner
 */
final class RestoreStep
{
    /** The data key lives in the job at most this long (contract engine↔restore). */
    public const KEY_TTL_SECONDS = 3600;

    /** Phases in which the restored tables are in place (the rename happened). */
    public const APPLIED_PHASES = ['refresh', 'update', 'finish'];

    /** Phases a cancel may still stop: nothing on the site has changed yet. */
    public const CANCELLABLE_PHASES = ['verify', 'maintenance', 'load', 'apply'];

    /** Marker of an undo in progress (the undo holds a restore job as its lock). */
    public const UNDO_PHASE = 'undo';

    /**
     * @param JobRepository $jobs
     * @param KeyService    $keys
     * @param LocalVault    $vault
     * @param RestoreLedger $ledger
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly KeyService $keys,
        private readonly LocalVault $vault,
        private readonly RestoreLedger $ledger,
    ) {
    }

    /**
     * @param object       $job   Job row (lease held).
     * @param SliceContext $slice
     * @return bool True when the job ended (done or failed).
     * @throws LeaseLost
     */
    public function run(object $job, SliceContext $slice): bool
    {
        $cursor = $this->jobs->cursor($job);
        try {
            if (($cursor['phase'] ?? null) === self::UNDO_PHASE) {
                throw new RestoreAborted('undo_interrupted');
            }
            $reader = $this->reader($job, $cursor);
            while (true) {
                $completed = match ($cursor['phase']) {
                    'verify' => $this->verify($reader, $cursor, $slice),
                    'maintenance' => $this->maintenanceOn($cursor),
                    'load' => $this->load($reader, $cursor, $slice),
                    'swap' => $this->swap($cursor, $slice),
                    'refresh' => $this->refresh($cursor),
                    'update' => $this->update($cursor, $slice),
                    'apply' => $this->apply($reader, $cursor, $slice),
                    'finish' => $this->finish($job, $cursor, $slice),
                    default => throw new RuntimeException('Unknown restore phase.'),
                };
                if ($cursor['phase'] === 'done') {
                    return true;
                }
                $slice->save($cursor);
                if (!$completed || $slice->expired()) {
                    return false;
                }
                if (!isset($cursor['dek'])) {
                    $reader = null;
                }
            }
        } catch (ArchiveRejected $e) {
            $this->failWith($job, $slice, $cursor, 'archive_rejected:' . $e->reason);
        } catch (RestoreRefused $e) {
            $this->failWith($job, $slice, $cursor, $e->reason);
        } catch (RestoreAborted $e) {
            $this->failWith($job, $slice, $cursor, $e->reason);
        }

        return true;
    }

    /**
     * Clean up after a restore job ended without finishing (failure, cancel, dead slices). The
     * caller has already ended the job under its lease.
     *
     *  - rename not done yet: shadows dropped, the site is as before;
     *  - rename done (the restored tables are in place): kept, the old copies become the undo
     *    point, the result says what failed;
     *  - tables in an unclear state: nothing dropped, the tables are quarantined for a person;
     *  - maintenance mode turned off when this restore turned it on.
     *
     * @param object               $job
     * @param array<string, mixed> $cursor Latest saved cursor of the job.
     * @param string               $reason
     * @return void
     */
    public function abort(object $job, #[\SensitiveParameter] array $cursor, string $reason): void
    {
        $status = 'failed';
        $reals = array_column((array) ($cursor['tables'] ?? []), 'real');
        $phase = $cursor['phase'] ?? null;
        try {
            if ($phase !== self::UNDO_PHASE && ($cursor['plan']['level'] ?? null) !== RestorePlan::UNDELETE && $reals !== []) {
                $swapper = new TableSwapper($this->schema());
                $state = $swapper->state($reals);
                $renamed = in_array($phase, self::APPLIED_PHASES, true) || ($phase === 'swap' && !empty($cursor['swapping']));
                if ($renamed && $state === 'swapped') {
                    $status = 'done_with_errors';
                    $this->ledger->recordUndoPoint($this->undoPoint($job, $cursor));
                } elseif ($state === 'mixed' && !empty($cursor['swapping'])) {
                    // Never drop a table whose role is unclear: it may hold the only copy of some data.
                    $this->ledger->quarantine($reals);
                    $status = 'quarantined';
                    BackupLog::failure('Restore job ' . $job->id . ' left tables in an unclear state; copies kept for inspection.');
                } else {
                    foreach ($reals as $real) {
                        $swapper->drop(TableSwapper::name($real, TableSwapper::SHADOW));
                    }
                }
            }
        } finally {
            $this->maintenanceOff($cursor);
            $this->ledger->recordLast($this->entry($job, $cursor, $status, $reason));
        }
    }

    /**
     * @param object               $job
     * @param array<string, mixed> $cursor
     * @return ArchiveReader|null Opened with the data key kept in the job, null once no longer needed.
     * @throws RestoreAborted When the key outlived its window.
     */
    private function reader(object $job, #[\SensitiveParameter] array $cursor): ?ArchiveReader
    {
        if (!isset($cursor['dek'])) {
            return null;
        }
        if (now()->getTimestamp() - (int) ($cursor['started'] ?? 0) > self::KEY_TTL_SECONDS) {
            throw new RestoreAborted('key_expired');
        }
        $origin = ArchiveOrigin::normalise((string) ($cursor['origin'] ?? ''));
        $reader = ArchiveReader::openWithDataKey(
            $this->vault->archiveDir((string) $job->archive_id),
            (string) base64_decode((string) $cursor['dek'], true),
            $origin === ArchiveReader::ORIGIN_SITE ? $this->keys->signingKey() : null,
            $origin
        );
        // The header must be the one the restore was started on (same key, same wrapped data key).
        $header = $reader->header();
        if (!hash_equals((string) ($cursor['key_id'] ?? ''), (string) $header['key_id'])
            || CanonicalJson::encode((array) ($cursor['dek_wrapped'] ?? [])) !== CanonicalJson::encode($header['dek'])) {
            throw new ArchiveRejected(ArchiveRejected::KEY_MISMATCH, 'header key');
        }

        return $reader;
    }

    /**
     * @param ArchiveReader        $reader
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool
     */
    private function verify(ArchiveReader $reader, #[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $steps = $reader->verifySteps();
        while ($cursor['step'] < count($steps)) {
            $reader->verifyStep($steps[$cursor['step']]);
            $cursor['step']++;
            if ($slice->expired()) {
                return false;
            }
        }
        $cursor['phase'] = match ($cursor['plan']['level']) {
            RestorePlan::UNDELETE => 'apply',
            RestorePlan::FULL => 'maintenance',
            default => 'load',
        };

        return true;
    }

    /**
     * Whose maintenance it is is told by the secret stored in Laravel's own maintenance data, so
     * a request dying right after turning it on cannot leave it on for good.
     *
     * @param array<string, mixed> $cursor
     * @return bool
     */
    private function maintenanceOn(#[\SensitiveParameter] array &$cursor): bool
    {
        $guard = app(MaintenanceGuard::class);
        if (!$guard->isActive()) {
            $guard->activate((string) $cursor['maintenance_secret'], (int) $cursor['started'] + self::KEY_TTL_SECONDS);
        }
        $cursor['phase'] = 'load';

        return true;
    }

    /**
     * @param ArchiveReader        $reader
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool False when the budget ran out in the middle.
     */
    private function load(ArchiveReader $reader, #[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $schema = $this->schema();
        $loader = new ShadowLoader($schema);
        while ($cursor['ti'] < count($cursor['tables'])) {
            $table = $cursor['tables'][$cursor['ti']];
            $shadow = TableSwapper::name($table['real'], TableSwapper::SHADOW);
            // WHY recreate: a slice that died inside a segment left an unknown number of rows.
            if (empty($cursor['created']) || !$schema->hasTable($shadow) || $loader->count($shadow) !== $cursor['loaded']) {
                $loader->create($table['real']);
                $cursor['created'] = true;
                $cursor['seg'] = 0;
                $cursor['loaded'] = 0;
            }
            $columns = $loader->commonColumns($table['columns'], $shadow);
            $segments = $reader->segmentCount($table['name']);
            while ($cursor['seg'] < $segments) {
                $cursor['loaded'] += $loader->insert($shadow, $columns, $reader->segmentRows($table['name'], $cursor['seg']));
                $cursor['seg']++;
                if ($slice->expired()) {
                    return false;
                }
                if ($slice->saveDue()) {
                    $slice->save($cursor);
                }
            }
            if ($cursor['loaded'] !== (int) $table['rows']) {
                throw new RestoreAborted('row_count');
            }
            ConfigRestoreRules::clearSessionTokens($schema->db(), $shadow, $columns);
            $cursor['ti']++;
            $cursor['created'] = false;
            if ($cursor['ti'] < count($cursor['tables'])) {
                $slice->save($cursor);
            }
        }
        $cursor['phase'] = 'swap';
        unset($cursor['dek']);

        return true;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool
     * @throws RestoreAborted When a shadow lost rows, or the tables are neither all loaded nor all swapped.
     */
    private function swap(#[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $reals = array_column($cursor['tables'], 'real');
        $schema = $this->schema();
        $swapper = new TableSwapper($schema);
        $state = $swapper->state($reals);
        if ($state === 'loaded') {
            $loader = new ShadowLoader($schema);
            foreach ($cursor['tables'] as $table) {
                $shadow = TableSwapper::name($table['real'], TableSwapper::SHADOW);
                if ($loader->count($shadow) !== (int) $table['rows']) {
                    throw new RestoreAborted('row_count');
                }
                // WHY here and not at load time: the key ring may have changed while the shadows
                // were loading over many slices — the rows kept are the ones current at the rename.
                if (ConfigRestoreRules::isConfigTable($table['real'])) {
                    ConfigRestoreRules::keepBackupRows($schema->db(), $table['real'], $shadow);
                }
                $cursor['expected'][$table['real']] = $loader->count($shadow);
            }
            $cursor['swapping'] = true;
            $slice->save($cursor);
            $cursor['undo_replaced'] = $swapper->swap($reals);
        } elseif ($state !== 'swapped' || empty($cursor['swapping'])) {
            // WHY "swapped" counts only after the marker: the request died right after the rename,
            // before saving. Without the marker the old copies may be an earlier restore's.
            throw new RestoreAborted('swap_state');
        }
        if ($swapper->state($reals) !== 'swapped') {
            throw new RestoreAborted('swap_state');
        }
        $cursor['swapped_at'] = now()->toIso8601String();
        $cursor['phase'] = 'refresh';

        return true;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return bool
     */
    private function refresh(#[\SensitiveParameter] array &$cursor): bool
    {
        ConfigRestoreRules::refreshCaches();
        $cursor['phase'] = $this->afterApplied($cursor);

        return true;
    }

    /**
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool
     */
    private function update(#[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        // Saved before running: a request dying inside the update does not run it a second time
        // (nor a second slice in parallel); "interrupted" tells the person to run it by hand.
        $cursor['phase'] = 'finish';
        $cursor['update'] = ['ok' => false, 'code' => null, 'error' => 'interrupted'];
        $slice->save($cursor);
        $cursor['update'] = app(DataUpdater::class)->run();

        return true;
    }

    /**
     * @param ArchiveReader        $reader
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool
     */
    private function apply(ArchiveReader $reader, #[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $undelete = new UndeleteService(
            $this->schema(),
            (int) config('gp247-config.backup.restore_undelete_max_rows', 5000),
            // Scanning a large archive table keeps the lease alive.
            function () use (&$cursor, $slice) {
                if ($slice->saveDue()) {
                    $slice->save($cursor);
                }
            }
        );
        $cursor['undelete'] = $undelete->apply($reader, $cursor['tables'][0], (array) ($cursor['children'] ?? []), $cursor['plan']['keys']);
        unset($cursor['dek']);
        $cursor['phase'] = $this->afterApplied($cursor);

        return true;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return string "update" for an older archive (or one of unknown version), else "finish".
     */
    private function afterApplied(#[\SensitiveParameter] array $cursor): string
    {
        return in_array($cursor['compatibility'], [CompatibilityGate::OLDER, CompatibilityGate::UNKNOWN], true) ? 'update' : 'finish';
    }

    /**
     * @param object               $job
     * @param array<string, mixed> $cursor
     * @param SliceContext         $slice
     * @return bool
     * @throws LeaseLost
     */
    private function finish(object $job, #[\SensitiveParameter] array &$cursor, SliceContext $slice): bool
    {
        $this->maintenanceOff($cursor);
        $swapping = $cursor['plan']['level'] !== RestorePlan::UNDELETE;
        $postIncident = !empty($cursor['post_incident']) && $cursor['plan']['level'] === RestorePlan::FULL;
        if ($postIncident) {
            // Written first: a request dying inside the steps still leaves the reminder (screen, doctor).
            $cursor['post_incident_result'] = [['step' => 'all', 'status' => 'failed', 'count' => 0, 'detail' => 'interrupted']];
            PostIncidentChecklist::open((int) $job->id, $cursor['post_incident_result']);
        }
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $slice, $cursor, $swapping) {
            if (!$this->jobs->finish((int) $job->id, $slice->owner, Archive::JOB_DONE)) {
                throw new LeaseLost;
            }
            if ($swapping) {
                $this->ledger->recordUndoPoint($this->undoPoint($job, $cursor));
            }
            $this->ledger->recordLast($this->entry($job, $cursor, 'done', null));
        });
        $cursor['phase'] = 'done';
        if ($postIncident) {
            $this->postIncident($job, $cursor);
        }

        return true;
    }

    /**
     * Post-incident steps, after the job ended (nothing of the restore depends on the session of
     * the person restoring any more). Each step is idempotent; the result goes to the checklist
     * and the restore result.
     *
     * @param object               $job
     * @param array<string, mixed> $cursor
     * @return void
     *
     * @aidlc-story US-backup-restore-post-incident-mode
     */
    private function postIncident(object $job, #[\SensitiveParameter] array &$cursor): void
    {
        $cursor['post_incident_result'] = app(PostIncidentService::class)->run();
        PostIncidentChecklist::open((int) $job->id, $cursor['post_incident_result']);
        $this->ledger->recordLast($this->entry($job, $cursor, 'done', null));
    }

    /**
     * The job is ended under its lease first; only then is anything undone, so a slice that lost
     * its lease never drops tables another slice is working on.
     *
     * @param object               $job
     * @param SliceContext         $slice
     * @param array<string, mixed> $cursor
     * @param string               $reason
     * @return void
     * @throws LeaseLost
     */
    private function failWith(object $job, SliceContext $slice, #[\SensitiveParameter] array $cursor, string $reason): void
    {
        if (!$this->jobs->finish((int) $job->id, $slice->owner, Archive::JOB_FAILED, 'restore: ' . $reason)) {
            throw new LeaseLost;
        }
        $this->abort($job, $cursor, $reason);
        BackupLog::failure('Restore job ' . $job->id . ' failed: ' . $reason);
    }

    /**
     * @param array<string, mixed> $cursor
     * @return void
     */
    private function maintenanceOff(#[\SensitiveParameter] array $cursor): void
    {
        $guard = app(MaintenanceGuard::class);
        if (is_string($cursor['maintenance_secret'] ?? null) && $guard->isOurs($cursor['maintenance_secret'])) {
            $guard->deactivate();
        }
    }

    /**
     * @param object               $job
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    private function undoPoint(object $job, #[\SensitiveParameter] array $cursor): array
    {
        return [
            'job_id' => (int) $job->id,
            'archive_id' => (string) $job->archive_id,
            'level' => (string) ($cursor['plan']['level'] ?? ''),
            'tables' => array_column((array) ($cursor['tables'] ?? []), 'real'),
            'swapped_at' => $cursor['swapped_at'] ?? now()->toIso8601String(),
            'undo_until' => now()->addHours(max(1, (int) config('gp247-config.backup.restore_undo_hours', 24)))->toIso8601String(),
        ];
    }

    /**
     * @param object               $job
     * @param array<string, mixed> $cursor
     * @param string               $status done | done_with_errors | failed | quarantined
     * @param string|null          $reason
     * @return array<string, mixed> Result without secrets or row data.
     */
    private function entry(object $job, #[\SensitiveParameter] array $cursor, string $status, ?string $reason): array
    {
        return [
            'job_id' => (int) $job->id,
            'archive_id' => (string) $job->archive_id,
            'level' => (string) ($cursor['plan']['level'] ?? ''),
            'status' => $status,
            'reason' => $reason,
            'names' => array_column((array) ($cursor['tables'] ?? []), 'name'),
            'rows' => (array) ($cursor['expected'] ?? []),
            'skipped' => (array) ($cursor['skipped'] ?? []),
            'compatibility' => (string) ($cursor['compatibility'] ?? ''),
            'update' => $cursor['update'] ?? null,
            'undelete' => $cursor['undelete'] ?? null,
            'origin' => (string) ($cursor['origin'] ?? ArchiveReader::ORIGIN_SITE),
            'post_incident' => $cursor['post_incident_result'] ?? null,
            'undo_replaced' => (array) ($cursor['undo_replaced'] ?? []),
            'swapped_at' => $cursor['swapped_at'] ?? null,
            'finished_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return SchemaInspector
     */
    private function schema(): SchemaInspector
    {
        return new SchemaInspector(DB::connection(GP247_DB_CONNECTION));
    }
}
