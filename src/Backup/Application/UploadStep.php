<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\NotPermitted;
use GP247\Core\Backup\Domain\RemoteLayout;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Support\Facades\DB;

/**
 * One slice of the copy of a verified archive to the external destination (Job "upload"): the
 * files of the archive one after the other — parts, manifest, header last — each checked after
 * writing (read back and hashed when the credential may read, else size). The cursor keeps the
 * next file, so a slice that runs out of time goes on from there; a file that did not check out
 * is written once more before the job fails. The archive is already encrypted: the destination
 * never sees data or keys.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-chunked-upload, US-backup-storage-s3-compatible, US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters, backup-engine_sliced-job-runner
 */
final class UploadStep
{
    private const READ_CHUNK = 1_048_576;

    /**
     * @param JobRepository       $jobs
     * @param ArchiveRepository   $archives
     * @param LocalVault          $vault
     * @param OffsiteService|null $offsite
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ArchiveRepository $archives,
        private readonly LocalVault $vault,
        private ?OffsiteService $offsite = null,
    ) {
    }

    /**
     * @param object       $job Job row (lease held).
     * @param SliceContext $slice
     * @return bool True when the job ended.
     * @throws LeaseLost
     * @throws DestinationFailure A failure a later slice may cure (network…).
     */
    public function run(object $job, SliceContext $slice): bool
    {
        $offsite = $this->offsite ??= new OffsiteService(null, null, $this->jobs, $this->archives);
        $cursor = $this->jobs->cursor($job);
        $archiveId = (string) $job->archive_id;
        $archive = $this->archives->find($archiveId);
        if ($archive === null || $archive->status !== Archive::STATUS_OK) {
            return $this->end($job, $slice, false, 'upload: ' . DestinationFailure::NOT_FOUND);
        }
        if (!isset($cursor['files'])) {
            $names = array_values(array_filter(scandir($this->vault->archiveDir($archiveId)) ?: [], fn ($n) => RemoteLayout::isFileName($n)));
            if (!in_array(RemoteLayout::HEADER, $names, true)) {
                return $this->end($job, $slice, false, 'upload: ' . DestinationFailure::NOT_FOUND);
            }
            $cursor = ['phase' => 'upload', 'files' => RemoteLayout::uploadOrder($names), 'i' => 0, 'retried' => false, 'site' => RemoteLayout::siteKey()];
        }

        $from = (int) $cursor['i'];
        try {
            $destination = $offsite->destination();
            $byRead = $offsite->canRead();
            while ($cursor['i'] < count($cursor['files'])) {
                $began = microtime(true);
                $name = (string) $cursor['files'][$cursor['i']];
                $local = $this->vault->archiveDir($archiveId) . DIRECTORY_SEPARATOR . $name;
                $path = RemoteLayout::path((string) $cursor['site'], $archiveId, $name);
                if (!$this->copy($destination, $local, $path, $byRead)) {
                    if (!empty($cursor['retried'])) {
                        throw new DestinationFailure(DestinationFailure::MISMATCH, $name);
                    }
                    $cursor['retried'] = true;
                    continue;
                }
                $cursor['i']++;
                $cursor['retried'] = false;
                // WHY: a file is copied and checked in one go; one that would not fit in the time
                // left (judged by the file just done) waits for the next slice instead of being cut
                // by max_execution_time on a slow shared host.
                $short = microtime(true) + (microtime(true) - $began) > $slice->deadline;
                if ($cursor['i'] < count($cursor['files']) && ($slice->expired() || $short || $slice->saveDue())) {
                    $slice->save($cursor);
                    if ($slice->expired() || $short) {
                        return false;
                    }
                }
            }
        } catch (DestinationFailure $e) {
            if (!$e->permanent() && $e->reason !== DestinationFailure::MISMATCH) {
                // Keep how far it got: the runner counts the attempt and the next slice goes on.
                // The attempt counter restarts only when a file got through in this slice, so a
                // destination that stays down ends the job (and its alert) after the allowed attempts.
                $slice->save($cursor, $cursor['i'] > $from);

                throw $e;
            }

            return $this->end($job, $slice, false, 'upload: ' . $e->reason);
        }

        $this->end($job, $slice, true, null);
        try {
            (new OffsiteRetention($offsite->config()))->run($destination, (string) $cursor['site']);
        } catch (\Throwable $e) {
            // The copy is done and recorded; old off-site copies stay until the next run.
            BackupLog::failure('Off-site retention after archive ' . $archiveId . ' failed: ' . class_basename($e));
        }

        return true;
    }

    /**
     * Called by the runner after it ended an upload job as failed.
     *
     * @param object $job
     * @param string $error
     * @return void
     */
    public function failed(object $job, string $error): void
    {
        $this->offsite ??= new OffsiteService(null, null, $this->jobs, $this->archives);
        $this->offsite->uploadFailed($job->archive_id === null ? null : (string) $job->archive_id, $error);
    }

    /**
     * @param Destination $destination
     * @param string      $local
     * @param string      $path
     * @param bool        $byRead Check by reading back (else by size).
     * @return bool The written file checks out.
     * @throws DestinationFailure
     */
    private function copy(Destination $destination, string $local, string $path, bool $byRead): bool
    {
        if (!is_file($local) || is_link($local)) {
            throw new DestinationFailure(DestinationFailure::NOT_FOUND, 'local file');
        }
        $size = (int) filesize($local);
        $hash = (string) hash_file('sha256', $local);
        $in = fopen($local, 'rb');
        if ($in === false) {
            throw new DestinationFailure(DestinationFailure::NOT_FOUND, 'local file');
        }
        try {
            $destination->put($path, $in);
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }
        if ($byRead) {
            try {
                return hash_equals($hash, $this->remoteHash($destination, $path, $size));
            } catch (NotPermitted $e) {
                // The report said "read" but this file cannot be read back: fall back to the size.
            }
        }

        return $destination->size($path) === $size;
    }

    /**
     * @param Destination $destination
     * @param string      $path
     * @param int         $size Expected size (reading stops one byte beyond it).
     * @return string SHA-256 of what the destination holds.
     * @throws DestinationFailure
     */
    private function remoteHash(Destination $destination, string $path, int $size): string
    {
        $stream = $destination->read($path);
        $context = hash_init('sha256');
        $read = 0;
        try {
            while (!feof($stream) && $read <= $size) {
                $chunk = fread($stream, self::READ_CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $read += strlen($chunk);
                hash_update($context, $chunk);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($context);
    }

    /**
     * @param object       $job
     * @param SliceContext $slice
     * @param bool         $done
     * @param string|null  $error
     * @return bool Always true (the job ended).
     * @throws LeaseLost
     */
    private function end(object $job, SliceContext $slice, bool $done, ?string $error): bool
    {
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $slice, $done, $error) {
            if (!$this->jobs->finish((int) $job->id, $slice->owner, $done ? Archive::JOB_DONE : Archive::JOB_FAILED, $error)) {
                throw new LeaseLost;
            }
            if ($done && $this->archives->find((string) $job->archive_id) !== null) {
                $this->archives->update((string) $job->archive_id, ['offsite' => Archive::OFFSITE_DONE]);
            }
        });
        if ($done) {
            BackupAudit::record('offsite_upload', BackupAudit::OK, ['archive_id' => (string) $job->archive_id]);
        } else {
            $this->failed($job, (string) $error);
        }

        return true;
    }
}
