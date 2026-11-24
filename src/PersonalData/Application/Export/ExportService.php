<?php

namespace GP247\Core\PersonalData\Application\Export;

use GP247\Core\PersonalData\Application\Erasure\ErasureJobRunner;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\SourceQuery;
use GP247\Core\PersonalData\Application\SubjectLookup;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\Export\ExportStorage;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A customer's data package: built in slices by the personal data job runner (no cron, no
 * queue), then downloadable once, for 24 hours, by the person the link was given to. The file
 * is removed once downloaded, and a package nobody downloaded is removed after its 24 hours.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package, US-customer-privacy-self-export
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ExportService
{
    private const PRUNE_LOCK = 'gp247:personal-data:export-prune';

    /**
     * @param ExportRepository $exports
     * @param JobRepository    $jobs
     * @param ErasureJobRunner $runner
     * @param SubjectLookup    $lookup
     */
    public function __construct(
        private readonly ExportRepository $exports,
        private readonly JobRepository $jobs,
        private readonly ErasureJobRunner $runner,
        private readonly SubjectLookup $lookup,
    ) {
    }

    /**
     * @return bool Whether this site can build packages (tables present, ZIP available).
     */
    public static function ready(): bool
    {
        return self::zipAvailable() && ErasureService::ready()
            && SourceQuery::db()->getSchemaBuilder()->hasTable(PersonalDataSchema::table(PersonalDataSchema::EXPORT));
    }

    /**
     * @return bool
     */
    public static function zipAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Start building a package. The first slice runs on the next continue().
     *
     * @param DataSubject $subject
     * @param string|null $requestId
     * @param string      $origin     admin | customer
     * @param string|null $actorGuard
     * @param string|null $actorId
     * @param bool        $matchEmails Also gather rows found by the account's email (guest orders, messages).
     *                                 False for a customer whose email is not verified: anyone can sign
     *                                 up with someone else's address.
     * @param string      $audience    customer (web, app) or admin.
     * @return string Package id.
     * @throws \RuntimeException When packages cannot be built on this site.
     */
    public function start(DataSubject $subject, ?string $requestId, string $origin, ?string $actorGuard, ?string $actorId, bool $matchEmails = true, string $audience = ExportRepository::AUDIENCE_CUSTOMER): string
    {
        if (!self::ready()) {
            throw new \RuntimeException('Data packages cannot be built on this server.');
        }
        $subject = $matchEmails ? $this->lookup->expand($subject) : ($subject->customerId === null ? $subject : DataSubject::customer($subject->customerId));
        $exportId = $this->exports->create($requestId, $subject->customerId, $audience);
        $jobId = $this->jobs->create(JobRepository::KIND_EXPORT, $origin, $subject->customerId, $actorGuard, $actorId, null, ExportSteps::start($subject, $exportId, $requestId));
        $this->exports->update($exportId, ['job_id' => $jobId]);

        return $exportId;
    }

    /**
     * Run one more slice of the package when it is still being built.
     *
     * @param string $exportId
     * @return string Package status (building, ready, downloaded, expired, failed).
     */
    public function continue(string $exportId): string
    {
        $export = $this->exports->find($exportId);
        if ($export === null) {
            return ExportRepository::FAILED;
        }
        if ((string) $export->status === ExportRepository::BUILDING && $export->job_id !== null) {
            $status = $this->runner->runSlice((string) $export->job_id);
            if ($status === JobRepository::FAILED) {
                $this->exports->update($exportId, ['status' => ExportRepository::FAILED]);
            }
            $export = $this->exports->find($exportId);
        }

        return (string) ($export->status ?? ExportRepository::FAILED);
    }

    /**
     * @param string $exportId
     * @return object|null
     */
    public function find(string $exportId): ?object
    {
        return $this->exports->find($exportId);
    }

    /**
     * @param string $customerId
     * @param string $audience   customer or admin: the customer's own package and the one an
     *                           administrator prepares are counted apart.
     * @return object|null The package of the last 24 hours, if any (one per 24 hours).
     */
    public function recentFor(string $customerId, string $audience = ExportRepository::AUDIENCE_CUSTOMER): ?object
    {
        return $this->exports->latestFor($customerId, now()->subHours(ExportSteps::HOURS), $audience);
    }

    /**
     * The customer is being erased: their packages (built or being built) must not outlive it.
     *
     * @param string $customerId
     * @return void
     */
    public function discardFor(string $customerId): void
    {
        if (!SourceQuery::db()->getSchemaBuilder()->hasTable(PersonalDataSchema::table(PersonalDataSchema::EXPORT))) {
            return;
        }
        foreach ($this->exports->openFor($customerId) as $export) {
            if ($export->job_id !== null) {
                $this->jobs->cancel((string) $export->job_id);
            }
            ExportStorage::remove((string) $export->id);
            $this->exports->markCleaned((string) $export->id, ExportRepository::FAILED);
        }
    }

    /**
     * prune() at most once a minute — from customer pages and the API too, so packages leave the
     * server on time on a shop whose administrator rarely signs in.
     *
     * @return void
     */
    public function pruneSometimes(): void
    {
        if (self::ready() && Cache::add(self::PRUNE_LOCK, 1, 60)) {
            try {
                $this->prune();
            } catch (\Throwable $e) {
                if (function_exists('gp247_report')) {
                    gp247_report('Personal data package clean-up failed: ' . $e::class);
                }
            }
        }
    }

    /**
     * Give a download link to one person. A new link replaces the previous one.
     *
     * @param string $exportId
     * @param string $owner    customer:<id> or admin:<id>.
     * @return string|null The secret part of the link, or null when the package cannot be downloaded.
     */
    public function issueToken(string $exportId, string $owner): ?string
    {
        $export = $this->exports->find($exportId);
        if ($export === null || (string) $export->status !== ExportRepository::READY || $export->expires_at === null || now()->greaterThanOrEqualTo($export->expires_at)
            || (int) $export->downloads >= ExportRepository::MAX_DOWNLOADS) {
            return null;
        }
        $token = Str::random(48);
        $this->exports->setToken($exportId, hash('sha256', $token), $owner);

        return $token;
    }

    /**
     * Use a download link: valid once, for its owner, within the 24 hours.
     *
     * @param string $exportId
     * @param string $token
     * @param string $owner
     * @return string|null Path of the ZIP to send, or null when refused. Remove the file after sending
     *                     only when lastDownload() says so.
     */
    public function claim(string $exportId, #[\SensitiveParameter] string $token, string $owner): ?string
    {
        if (preg_match('/^[0-9A-Za-z]{26}$/', $exportId) !== 1 || $token === '') {
            return null;
        }
        if (!$this->exports->claim($exportId, hash('sha256', $token), $owner)) {
            return null;
        }
        $path = ExportStorage::zipPath($exportId);

        return is_file($path) ? $path : null;
    }

    /**
     * @param string $exportId
     * @return bool Whether the package was just downloaded for the last time (remove the file after sending).
     */
    public function lastDownload(string $exportId): bool
    {
        $export = $this->exports->find($exportId);

        return $export === null || (string) $export->status !== ExportRepository::READY;
    }

    /**
     * Remove the files of packages downloaded, failed, or past their 24 hours.
     *
     * @return int Packages cleaned.
     */
    public function prune(): int
    {
        foreach ($this->exports->building() as $export) {
            $job = $export->job_id === null ? null : $this->jobs->find((string) $export->job_id);
            // WHY: a job that gave up (or vanished) leaves its package "building" forever otherwise.
            if ($job === null || (string) $job->status === JobRepository::FAILED) {
                $this->exports->update((string) $export->id, ['status' => ExportRepository::FAILED]);
            }
        }
        $done = 0;
        foreach ($this->exports->toClean(now()) as $export) {
            ExportStorage::remove((string) $export->id);
            $status = (string) $export->status === ExportRepository::READY ? ExportRepository::EXPIRED : (string) $export->status;
            $this->exports->markCleaned((string) $export->id, $status);
            $done++;
        }

        return $done;
    }
}
