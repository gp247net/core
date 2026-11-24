<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\PersonalData\Application\Erasure\ErasureJobRunner;
use GP247\Core\PersonalData\Application\Erasure\ErasureSteps;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;

/**
 * The one way to erase a customer's personal data, whoever asks (administrator, the customer,
 * a guest request, retention, a restore): every registered blocker is asked first; then the
 * ledger entry is written, the job is created and its first slice runs. Later slices run when
 * the screen polls or after the next administrator request.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-personal-data-registry-erasure-ledger
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ErasureService
{
    public const ORIGINS = ['admin', 'customer', 'guest', 'retention', 'restore'];
    private const RESUME_SECONDS = 5.0;

    /** @var bool|null Memo: whether the job table exists (sites not updated yet). */
    private static ?bool $ready = null;

    /**
     * @param SubjectLookup    $lookup
     * @param ErasureLedger    $ledger
     * @param JobRepository    $jobs
     * @param ErasureJobRunner $runner
     */
    public function __construct(
        private readonly SubjectLookup $lookup,
        private readonly ErasureLedger $ledger,
        private readonly JobRepository $jobs,
        private readonly ErasureJobRunner $runner,
    ) {
    }

    /**
     * @param DataSubject $subject
     * @return list<array{reason: string, ref: string, label: string}> Reasons not to erase now.
     */
    public function blockers(DataSubject $subject): array
    {
        $out = [];
        foreach (PersonalDataRegistry::blockers() as $blocker) {
            foreach ($blocker->blockers($subject) as $reason) {
                $out[] = $reason;
            }
        }

        return $out;
    }

    /**
     * @param DataSubject $subject
     * @param string      $origin     One of ORIGINS.
     * @param string|null $actorGuard Guard of the person who asked (admin, customer), if any.
     * @param string|null $actorId
     * @param bool        $runNow     Run the first slice in this request.
     * @return ErasureOutcome
     * @throws \InvalidArgumentException When the origin is unknown.
     * @throws \RuntimeException         When the feature tables are missing (site not updated).
     */
    public function request(DataSubject $subject, string $origin, ?string $actorGuard = null, ?string $actorId = null, bool $runNow = true): ErasureOutcome
    {
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException('Unknown erasure origin');
        }
        if (!self::ready()) {
            throw new \RuntimeException('Run php artisan gp247:update before erasing personal data.');
        }
        $blockers = $this->blockers($subject);
        if ($blockers !== []) {
            return new ErasureOutcome(ErasureOutcome::BLOCKED, blockers: $blockers);
        }
        $subject = $this->lookup->expand($subject);
        [$jobId, $erasureId] = SourceQuery::db()->transaction(function () use ($subject, $origin, $actorGuard, $actorId) {
            $erasureId = $this->ledger->open($subject->customerId, $subject->emails, $origin);
            $jobId = $this->jobs->create('erase', $origin, $subject->customerId, $actorGuard, $actorId, $erasureId, ErasureSteps::start($subject, $erasureId));
            $this->ledger->attachJob($erasureId, $jobId);

            return [$jobId, $erasureId];
        });
        $status = $runNow ? $this->runner->runSlice($jobId) : JobRepository::QUEUED;

        return new ErasureOutcome($status, $jobId, $erasureId);
    }

    /**
     * @param string $jobId
     * @return string Status after one more slice.
     */
    public function continue(string $jobId): string
    {
        return $this->runner->runSlice($jobId);
    }

    /**
     * @param string $jobId
     * @return string
     */
    public function status(string $jobId): string
    {
        return $this->runner->status($jobId);
    }

    /**
     * One short slice of any unfinished job — called after administrator requests, so an
     * erasure whose screen was closed still finishes without cron.
     *
     * @return void
     */
    public function resumeOne(): void
    {
        if (!self::ready()) {
            return;
        }
        $id = $this->jobs->nextRunnable();
        if ($id !== null) {
            $this->runner->runSlice($id, self::RESUME_SECONDS);
        }
    }

    /**
     * @return bool Whether the feature tables exist.
     */
    public static function ready(): bool
    {
        return self::$ready ??= SourceQuery::db()->getSchemaBuilder()->hasTable(PersonalDataSchema::table(PersonalDataSchema::JOB))
            && SourceQuery::db()->getSchemaBuilder()->hasTable(PersonalDataSchema::table(PersonalDataSchema::ERASURE));
    }

    /**
     * @return void Forget the memo (tests, after gp247:update in the same process).
     */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }
}
