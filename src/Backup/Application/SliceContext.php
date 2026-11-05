<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Infrastructure\Store\JobRepository;

/**
 * What a job step needs to know about the slice it runs in: when to stop, and how to save its
 * progress under the lease it holds. Saves are throttled to one per second (BK-2 decision 1d):
 * a dying request loses at most about a second of work, and a large archive does not rewrite
 * its growing cursor after every segment.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class SliceContext
{
    private const SAVE_INTERVAL = 1.0;

    private float $lastSave;

    private float $startedAt;

    /**
     * @param int           $jobId
     * @param string        $owner        Lease owner of this slice.
     * @param float         $deadline     microtime(true) after which the step must stop (INF = CLI).
     * @param int           $leaseSeconds Lease renewed on every save.
     * @param JobRepository $jobs
     */
    public function __construct(
        public readonly int $jobId,
        public readonly string $owner,
        public readonly float $deadline,
        public readonly int $leaseSeconds,
        private readonly JobRepository $jobs,
    ) {
        $this->lastSave = microtime(true);
        $this->startedAt = $this->lastSave;
    }

    /**
     * @return bool Half of the budget is used — no new work that cannot be split may start.
     */
    public function halfSpent(): bool
    {
        if (!is_finite($this->deadline)) {
            return false;
        }

        return microtime(true) - $this->startedAt >= ($this->deadline - $this->startedAt) / 2;
    }

    /**
     * @return bool The time budget is spent.
     */
    public function expired(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    /**
     * @return bool A throttled save would write now.
     */
    public function saveDue(): bool
    {
        return microtime(true) - $this->lastSave >= self::SAVE_INTERVAL;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return void
     * @throws LeaseLost When another slice took over or the job was cancelled.
     */
    public function save(#[\SensitiveParameter] array $cursor): void
    {
        if (!$this->jobs->saveCursor($this->jobId, $this->owner, $cursor, true, now()->addSeconds($this->leaseSeconds))) {
            throw new LeaseLost;
        }
        $this->lastSave = microtime(true);
    }
}
