<?php

namespace GP247\Core\PersonalData\Application;

/**
 * What happened to an erasure request: blocked (nothing written), running (continues in later
 * slices), done or failed.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ErasureOutcome
{
    public const BLOCKED = 'blocked';

    /**
     * @param string                                                  $status   blocked | queued | running | done | failed
     * @param string|null                                             $jobId
     * @param string|null                                             $erasureId
     * @param list<array{reason: string, ref: string, label: string}> $blockers
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $jobId = null,
        public readonly ?string $erasureId = null,
        public readonly array $blockers = [],
    ) {
    }

    /**
     * @return bool
     */
    public function isBlocked(): bool
    {
        return $this->status === self::BLOCKED;
    }

    /**
     * @return bool
     */
    public function isDone(): bool
    {
        return $this->status === 'done';
    }
}
