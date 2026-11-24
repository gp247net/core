<?php

namespace GP247\Core\PersonalData\Application;

use Carbon\Carbon;
use GP247\Core\Backup\Events\RestoreApplied;
use GP247\Core\PersonalData\Application\Erasure\ErasureSteps;
use GP247\Core\PersonalData\Infrastructure\JobRepository;

/**
 * After a restore put older data back into some tables, every erasure the ledger says the
 * restored data may still hold — requested after that data was saved, or not finished by then;
 * every entry when that time is unknown — is applied again, on those tables only. One job per
 * restore (the email columns are read once for all entries), which then queues one job per
 * entry; they run in slices after later admin requests, so the restore itself is not slowed.
 * Blockers are not asked: the customer was erased already.
 *
 * An archive from another site (or one whose origin cannot be verified) has its own customer
 * ids: there, entries are matched by email hash only — never by id, which may be someone else's.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-restore-reapply
 * @aidlc-adr personal-data-registry_restore-reapply
 */
final class ReapplyService
{
    /**
     * @param ErasureLedger $ledger
     * @param JobRepository $jobs
     */
    public function __construct(private readonly ErasureLedger $ledger, private readonly JobRepository $jobs)
    {
    }

    /**
     * @param RestoreApplied $event
     * @return int Customers (ledger entries) whose erasure will be applied again.
     */
    public function handle(RestoreApplied $event): int
    {
        if (!ErasureService::ready()) {
            return 0;
        }
        $tables = $this->declaredAmong($event->tables);
        if ($tables === []) {
            return 0;
        }
        $trustIds = $event->origin === RestoreApplied::ORIGIN_SITE;
        $entries = [];
        foreach ($this->ledger->since($this->time($event->dataAsOf)) as $entry) {
            if (!$trustIds) {
                $entry['customer_id'] = null;
            }
            if ($entry['customer_id'] !== null || $entry['hashes'] !== []) {
                $entries[] = $entry;
            }
        }
        if ($entries === []) {
            return 0;
        }
        $this->jobs->create('reapply', 'restore', null, null, null, null, ErasureSteps::startReapply($entries, $tables));

        return count($entries);
    }

    /**
     * @return int Erasures applied again after a restore that gave up (shown on the restore screen).
     */
    public static function failedCount(): int
    {
        try {
            return ErasureService::ready() ? app(JobRepository::class)->failedReapplies() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param list<string> $realTables Real names of the restored tables.
     * @return list<string> Declared source tables among them (logical names, processing order).
     */
    private function declaredAmong(array $realTables): array
    {
        $restored = array_flip(array_map('strval', $realTables));
        $out = [];
        foreach (PersonalDataRegistry::sources() as $source) {
            if (isset($restored[$source->physicalTable()])) {
                $out[] = $source->table;
            }
        }

        return $out;
    }

    /**
     * @param string $iso
     * @return \DateTimeInterface|null The time in the application time zone (how the ledger stores
     *                                 it); null when unknown or unreadable — every entry is applied again.
     */
    private function time(string $iso): ?\DateTimeInterface
    {
        if (trim($iso) === '') {
            return null;
        }
        try {
            return Carbon::parse($iso)->setTimezone((string) config('app.timezone', 'UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
