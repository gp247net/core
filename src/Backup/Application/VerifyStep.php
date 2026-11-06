<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Format\CanonicalJson;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Support\Facades\DB;

/**
 * One slice of the verification that follows every new archive: the archive is read back with
 * the data key its writer still held (no password needed) — signature, manifest, every part
 * hash, every frame, every segment hash — in resumable steps. Passed ⇒ "ok" and retention runs;
 * failed ⇒ "broken" with the reason, never counted as a successful backup. The data key leaves
 * the job cursor in both cases.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-integrity-manifest, US-backup-engine-retention, US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_tamper-evidence, backup-engine_sliced-job-runner
 */
final class VerifyStep
{
    /**
     * @param JobRepository     $jobs
     * @param ArchiveRepository $archives
     * @param KeyService        $keys
     * @param LocalVault        $vault
     * @param RetentionService  $retention
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ArchiveRepository $archives,
        private readonly KeyService $keys,
        private readonly LocalVault $vault,
        private readonly RetentionService $retention,
    ) {
    }

    /**
     * @param object       $job Job row (lease held).
     * @param SliceContext $slice
     * @return bool True when the verification ended (passed or not).
     * @throws LeaseLost
     */
    public function run(object $job, SliceContext $slice): bool
    {
        $cursor = $this->jobs->cursor($job);
        $archiveId = (string) $job->archive_id;
        $dek = base64_decode((string) ($cursor['dek'] ?? ''), true);

        try {
            $unverified = ArchiveOrigin::normalise((string) ($cursor['origin'] ?? '')) === ArchiveReader::ORIGIN_UNVERIFIED;
            $reader = ArchiveReader::openWithDataKey(
                $this->vault->archiveDir($archiveId),
                (string) $dek,
                $unverified ? null : $this->keys->signingKey(),
                $unverified ? ArchiveReader::ORIGIN_UNVERIFIED : ArchiveReader::ORIGIN_SITE
            );
            // The header must carry the data key wrapped for the site key it was made with —
            // otherwise the data checks out but no password could ever open the archive.
            $header = $reader->header();
            if (!hash_equals((string) ($cursor['key_id'] ?? ''), (string) $header['key_id'])
                || CanonicalJson::encode((array) ($cursor['dek_wrapped'] ?? [])) !== CanonicalJson::encode($header['dek'])) {
                throw new ArchiveRejected(ArchiveRejected::KEY_MISMATCH, 'header key');
            }
            $steps = $reader->verifySteps();
            $position = (int) ($cursor['step'] ?? 0);
            while ($position < count($steps)) {
                $reader->verifyStep($steps[$position]);
                $position++;
                if ($position < count($steps) && ($slice->expired() || $slice->saveDue())) {
                    $cursor['step'] = $position;
                    $slice->save($cursor);
                    if ($slice->expired()) {
                        return false;
                    }
                }
            }
        } catch (ArchiveRejected $e) {
            $this->end($job, $slice, Archive::STATUS_BROKEN, Archive::JOB_FAILED, 'verify: ' . $e->reason);
            BackupLog::failure('Backup archive ' . $archiveId . ' failed verification: ' . $e->reason);

            return true;
        }

        $this->end($job, $slice, Archive::STATUS_OK, Archive::JOB_DONE, null);
        try {
            $this->retention->apply();
        } catch (\Throwable $e) {
            // The new archive is good and recorded; old ones stay until the next run.
            BackupLog::failure('Retention after archive ' . $archiveId . ' failed: ' . class_basename($e));
        }

        return true;
    }

    /**
     * @param object       $job
     * @param SliceContext $slice
     * @param string       $archiveStatus
     * @param string       $jobStatus
     * @param string|null  $error
     * @return void
     */
    private function end(object $job, SliceContext $slice, string $archiveStatus, string $jobStatus, ?string $error): void
    {
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $slice, $archiveStatus, $jobStatus, $error) {
            if (!$this->jobs->finish((int) $job->id, $slice->owner, $jobStatus, $error)) {
                throw new LeaseLost;
            }
            $values = ['status' => $archiveStatus, 'error' => $error];
            if ($archiveStatus === Archive::STATUS_OK) {
                $values['verified_at'] = now();
            }
            $this->archives->update((string) $job->archive_id, $values);
            if ($archiveStatus === Archive::STATUS_OK) {
                // Only a verified archive is copied out (BK-6): queued in the same transaction,
                // so an "ok" archive is never left without its copy when a destination is set.
                try {
                    (new OffsiteService(null, null, $this->jobs, $this->archives))->queueNew((string) $job->archive_id);
                } catch (\Throwable $e) {
                    // A good archive stays good: the copy can be started again from the backup screen.
                    BackupLog::failure('Off-site copy of archive ' . $job->archive_id . ' not queued: ' . class_basename($e));
                }
            }
        });
    }
}
