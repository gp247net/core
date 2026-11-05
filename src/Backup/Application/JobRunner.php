<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Backup\Infrastructure\Store\RestoreLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Works through backup jobs in slices — no worker, no cron, no queue (shared hosts). Whoever
 * pulls a slice (admin screen poll, browser beacon, CLI, scheduler) calls runSlice(); the lease
 * in backup_job makes concurrent pulls safe: only one runs, the others get "busy".
 *
 * Within its budget a slice moves on from a finished backup job to its verify job. A job that
 * fails or whose slice dies 3 times in a row without progress becomes "failed" (a failed backup
 * leaves no half-written files; its catalogue row says "broken" with the reason).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class JobRunner
{
    public const MAX_ATTEMPTS = 3;
    private const LEASE_MARGIN = 30;
    private const CLI_LEASE = 600;

    private JobRepository $jobs;
    private ArchiveRepository $archives;
    private LocalVault $vault;
    private BackupStep $backupStep;
    private VerifyStep $verifyStep;
    private RestoreStep $restoreStep;

    /**
     * @param JobRepository|null     $jobs
     * @param ArchiveRepository|null $archives
     * @param KeyService|null        $keys
     * @param LocalVault|null        $vault
     * @param RetentionService|null  $retention
     */
    public function __construct(
        ?JobRepository $jobs = null,
        ?ArchiveRepository $archives = null,
        ?KeyService $keys = null,
        ?LocalVault $vault = null,
        ?RetentionService $retention = null,
    ) {
        $this->jobs = $jobs ?? new JobRepository;
        $this->archives = $archives ?? new ArchiveRepository;
        $this->vault = $vault ?? app(LocalVault::class);
        $keys ??= new KeyService;
        $retention ??= new RetentionService($this->archives, $this->jobs, $this->vault);
        $this->backupStep = new BackupStep($this->jobs, $this->archives, $keys, $this->vault);
        $this->verifyStep = new VerifyStep($this->jobs, $this->archives, $keys, $this->vault, $retention);
        $this->restoreStep = new RestoreStep($this->jobs, $keys, $this->vault, new RestoreLedger);
    }

    /**
     * Run one slice.
     *
     * @param int|null   $jobId  Job to work on (null = the oldest active job).
     * @param float|null $budget Seconds (null = SliceBudget; INF = until the archive is done).
     * @return array{status: string, job_id: int|null, archive_id: string|null, archive_status: string|null}
     *         status: idle | busy | running | done | failed | error.
     */
    public function runSlice(?int $jobId = null, ?float $budget = null): array
    {
        $budget ??= SliceBudget::seconds();
        $deadline = microtime(true) + $budget;
        $leaseSeconds = is_finite($budget) ? (int) ceil($budget) + self::LEASE_MARGIN : self::CLI_LEASE;
        $job = $jobId === null ? $this->jobs->nextActive() : $this->jobs->find($jobId);
        if ($job === null || !in_array($job->status, Archive::JOB_ACTIVE, true)) {
            if ($job === null) {
                $this->sweepCopies();
            }

            return $this->result($job === null ? 'idle' : $this->outcome($job), $job);
        }

        while (true) {
            $owner = bin2hex(random_bytes(16));
            if (!$this->jobs->acquire((int) $job->id, $owner, now()->addSeconds($leaseSeconds))) {
                return $this->result('busy', $job);
            }
            $job = $this->jobs->find((int) $job->id);
            if ((int) $job->attempts >= self::MAX_ATTEMPTS) {
                $this->fail($job, $owner, (string) ($job->error ?: 'stalled'));

                return $this->result('failed', $job);
            }

            $slice = new SliceContext((int) $job->id, $owner, $deadline, $leaseSeconds, $this->jobs);
            try {
                $finished = match ($job->type) {
                    Archive::JOB_BACKUP => $this->backupStep->run($job, $slice),
                    Archive::JOB_VERIFY => $this->verifyStep->run($job, $slice),
                    Archive::JOB_RESTORE => $this->restoreStep->run($job, $slice),
                    default => throw new \RuntimeException('Unknown job type.'),
                };
            } catch (LeaseLost $e) {
                return $this->leaseLost($job);
            } catch (\Throwable $e) {
                $error = self::describe($e);
                BackupLog::failure('Backup job ' . $job->id . ' (' . $job->type . ') error: ' . $error);
                // The last allowed attempt ends the job while this slice still holds the lease.
                if ((int) $job->attempts + 1 >= self::MAX_ATTEMPTS) {
                    $this->fail($job, $owner, $error);

                    return $this->result('failed', $job);
                }
                if ($this->jobs->recordError((int) $job->id, $owner, $error) === null) {
                    return $this->leaseLost($job);
                }

                return $this->result('error', $job);
            }

            if (!$finished) {
                $this->jobs->release((int) $job->id, $owner);

                return $this->result('running', $job);
            }
            $this->announce($job);
            $next = $job->archive_id === null || $job->type === Archive::JOB_RESTORE ? null : ($this->jobs->activeFor((string) $job->archive_id)[0] ?? null);
            if ($next === null || microtime(true) >= $deadline) {
                return $this->result($next === null ? $this->outcome($job) : 'running', $job);
            }
            $job = $next;
        }
    }

    /**
     * Run a job and whatever follows it for the same archive (verification) without a time
     * budget, in one slice — the CLI path, which reads every table in one database snapshot.
     *
     * @param int $jobId
     * @return array See runSlice().
     */
    public function runToEnd(int $jobId): array
    {
        return $this->runSlice($jobId, INF);
    }

    /**
     * End a job as failed; a failed backup loses its partial files.
     *
     * @param object $job
     * @param string $owner Lease owner (the job is ended only while this slice holds it).
     * @param string $error
     * @return void
     */
    private function fail(object $job, string $owner, string $error): void
    {
        $restore = $job->type === Archive::JOB_RESTORE;
        // Read again, and before finish() clears it: the slice that just failed may have moved
        // the restore on (up to the rename) since the row this runner holds was fetched.
        $cursor = $restore ? $this->restoreCursor($this->jobs->find((int) $job->id) ?? $job) : [];
        $ended = DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $owner, $error, $restore) {
            if (!$this->jobs->finish((int) $job->id, $owner, Archive::JOB_FAILED, $error)) {
                return false;
            }
            // A restore that fails says nothing about its source archive.
            if ($job->archive_id !== null && !$restore) {
                $this->archives->update((string) $job->archive_id, ['status' => Archive::STATUS_BROKEN, 'error' => mb_substr($job->type . ': ' . $error, 0, 255)]);
            }

            return true;
        });
        if (!$ended) {
            return;
        }
        if ($restore) {
            try {
                $this->restoreStep->abort($job, $cursor, $error);
            } catch (\Throwable $e) {
                BackupLog::failure('Restore job ' . $job->id . ' could not be rolled back: ' . self::describe($e));
            }
        }
        if ($job->type === Archive::JOB_BACKUP && $job->archive_id !== null) {
            $this->vault->deleteArchive((string) $job->archive_id);
        }
        BackupLog::failure('Backup job ' . $job->id . ' failed: ' . $error);
        $this->announce($job);
    }

    /**
     * Tell the site owner how a job ended (US-backup-admin-notifications): a failed backup or
     * check, or the end of a restore. Called once, by the slice that ended the job; a mail error
     * never reaches the runner.
     *
     * @param object $job
     * @return void
     *
     * @aidlc-unit backup-admin
     * @aidlc-story US-backup-admin-notifications
     */
    private function announce(object $job): void
    {
        try {
            $ended = $this->jobs->find((int) $job->id);
            if ($ended === null) {
                return;
            }
            $notifier = app(BackupNotifier::class);
            $archiveId = $ended->archive_id === null ? null : (string) $ended->archive_id;
            if ($ended->type === Archive::JOB_RESTORE && in_array($ended->status, [Archive::JOB_DONE, Archive::JOB_FAILED], true)) {
                $notifier->restoreFinished($archiveId, (string) $ended->status, $ended->error === null ? null : (string) $ended->error);
            } elseif (in_array($ended->type, [Archive::JOB_BACKUP, Archive::JOB_VERIFY], true) && $ended->status === Archive::JOB_FAILED && $archiveId !== null) {
                $notifier->backupFailed($archiveId, (string) $ended->error);
            }
        } catch (\Throwable $e) {
            BackupLog::failure('Backup job ' . $job->id . ' outcome not announced: ' . $e->getMessage());
        }
    }

    /**
     * The slice lost its job: taken over (busy), or cancelled — then anything it wrote after the
     * cancellation removed the files is removed again.
     *
     * @param object $job
     * @return array
     */
    private function leaseLost(object $job): array
    {
        if ($job->type === Archive::JOB_RESTORE) {
            return $this->result('busy', $job);
        }
        $archive = $job->archive_id === null ? null : $this->archives->find((string) $job->archive_id);
        if (($archive->status ?? null) === Archive::STATUS_CANCELLED) {
            $this->vault->deleteArchive((string) $job->archive_id);

            return $this->result('failed', $job);
        }

        return $this->result('busy', $job);
    }

    /**
     * @param object $job
     * @return string done | failed | running (from the archive status).
     */
    private function outcome(object $job): string
    {
        if ($job->type === Archive::JOB_RESTORE) {
            $status = $this->jobs->find((int) $job->id)->status ?? $job->status;

            return match (true) {
                $status === Archive::JOB_DONE => 'done',
                in_array($status, Archive::JOB_ACTIVE, true) => 'running',
                default => 'failed',
            };
        }
        $archive = $job->archive_id === null ? null : $this->archives->find((string) $job->archive_id);

        return match ($archive->status ?? null) {
            Archive::STATUS_OK => 'done',
            Archive::STATUS_BROKEN, Archive::STATUS_CANCELLED => 'failed',
            Archive::STATUS_RUNNING, Archive::STATUS_VERIFYING => 'running',
            default => in_array($job->status, Archive::JOB_ACTIVE, true) ? 'running' : 'done',
        };
    }

    /**
     * @param object $job
     * @return array<string, mixed> Cursor of a restore job, empty when unreadable.
     */
    private function restoreCursor(object $job): array
    {
        try {
            return $this->jobs->cursor($job);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * When the runner has nothing to do: restore copies whose undo window ended are dropped, and
     * a maintenance mode left by a restore that is no longer running is turned off.
     *
     * @return void
     */
    private function sweepCopies(): void
    {
        try {
            (new ShadowJanitor($this->jobs))->sweepExpired();
            (new RestoreWatchdog($this->jobs))->sweep();
        } catch (\Throwable $e) {
            BackupLog::failure('Clean-up of restore copies failed: ' . self::describe($e));
        }
    }

    /**
     * @param string      $status
     * @param object|null $job
     * @return array{status: string, job_id: int|null, archive_id: string|null, archive_status: string|null}
     */
    private function result(string $status, ?object $job): array
    {
        $archiveId = $job?->archive_id === null ? null : (string) $job->archive_id;

        return [
            'status' => $status,
            'job_id' => $job === null ? null : (int) $job->id,
            'archive_id' => $archiveId,
            'archive_status' => $archiveId === null ? null : ($this->archives->find($archiveId)->status ?? null),
        ];
    }

    /**
     * @param \Throwable $e
     * @return string Short description without data: SQL errors may quote row values.
     */
    private static function describe(\Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return 'database error ' . $e->getCode();
        }

        return class_basename($e) . ': ' . mb_substr($e->getMessage(), 0, 150);
    }
}
