<?php

namespace GP247\Core\PersonalData\Application\Erasure;

use GP247\Core\Backup\Application\SliceBudget;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use Illuminate\Support\Str;

/**
 * Runs one slice of an erasure job: takes the lease, advances the steps within the time
 * budget (the cursor is saved after every chunk), releases the lease. A request that dies
 * mid-slice loses at most one chunk; the next slice continues from the saved cursor. Three
 * failed attempts mark the job failed (the ledger entry stays, so it can be run again).
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ErasureJobRunner
{
    public const MAX_ATTEMPTS = 3;
    private const LEASE_MARGIN_SECONDS = 30;

    /**
     * @param JobRepository $jobs
     * @param ErasureSteps  $steps
     */
    public function __construct(private readonly JobRepository $jobs, private readonly ErasureSteps $steps)
    {
    }

    /**
     * @param string     $jobId
     * @param float|null $seconds Budget (default: SliceBudget).
     * @return string Job status after the slice.
     */
    public function runSlice(string $jobId, ?float $seconds = null): string
    {
        $budget = $seconds ?? SliceBudget::seconds();
        $owner = Str::random(32);
        if (!$this->jobs->acquire($jobId, $owner, now()->addSeconds((int) ceil($budget) + self::LEASE_MARGIN_SECONDS))) {
            return $this->status($jobId);
        }
        $job = $this->jobs->find($jobId);
        if ($job === null) {
            return JobRepository::FAILED;
        }
        $cursor = [];
        try {
            $cursor = $this->jobs->cursor($job);
            if ((int) $job->attempts >= self::MAX_ATTEMPTS) {
                $this->jobs->release($jobId, $owner, JobRepository::FAILED, $cursor, 'Too many failed attempts');

                return JobRepository::FAILED;
            }
            $cursor = $this->steps->advance(
                $cursor,
                microtime(true) + $budget,
                fn (array $c) => $this->jobs->save($jobId, $owner, $c),
            );
        } catch (\Throwable $e) {
            $this->fail($jobId, $owner, $cursor, (int) $job->attempts, $e);

            return $this->status($jobId);
        }
        if (($cursor['phase'] ?? null) === ErasureSteps::DONE) {
            $this->jobs->release($jobId, $owner, JobRepository::DONE, null);

            return JobRepository::DONE;
        }
        $this->jobs->release($jobId, $owner, JobRepository::RUNNING, $cursor);

        return JobRepository::RUNNING;
    }

    /**
     * @param string $jobId
     * @return string
     */
    public function status(string $jobId): string
    {
        return (string) ($this->jobs->find($jobId)->status ?? JobRepository::FAILED);
    }

    /**
     * @param string               $jobId
     * @param string               $owner
     * @param array<string, mixed> $cursor
     * @param int                  $attempts
     * @param \Throwable           $e
     * @return void
     */
    private function fail(string $jobId, string $owner, array $cursor, int $attempts, \Throwable $e): void
    {
        $final = $attempts + 1 >= self::MAX_ATTEMPTS;
        // WHY a fixed message: the exception text may quote a value being erased (SQL error).
        $this->jobs->release(
            $jobId,
            $owner,
            $final ? JobRepository::FAILED : JobRepository::RUNNING,
            $cursor === [] ? null : $cursor,
            $final ? 'Erasure stopped after repeated errors' : 'Erasure error, will retry',
            failedAttempt: true,
        );
        if (function_exists('gp247_report')) {
            gp247_report('Personal data erasure job ' . $jobId . ' failed: ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    }
}
