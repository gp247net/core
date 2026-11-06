<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ConnectionReport;
use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\DestinationCapabilities;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\NotPermitted;
use GP247\Core\Backup\Domain\OffsiteRefused;
use GP247\Core\Backup\Domain\RemoteArchive;
use GP247\Core\Backup\Domain\RemoteLayout;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Support\Facades\DB;

/**
 * The external destination as the rest of the backup feature sees it: queue the copy of a
 * verified archive (Job "upload"), copy again after a failure, test a configuration, list what
 * the destination holds, forget the destination. Only archives this site made and verified are
 * ever copied; the local copy is never touched by anything here.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-chunked-upload, US-backup-storage-fetch-remote, US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters, backup-engine_sliced-job-runner
 */
final class OffsiteService
{
    private DestinationConfig $config;
    private DestinationRegistry $registry;
    private JobRepository $jobs;
    private ArchiveRepository $archives;

    /**
     * @param DestinationConfig|null   $config
     * @param DestinationRegistry|null $registry
     * @param JobRepository|null       $jobs
     * @param ArchiveRepository|null   $archives
     */
    public function __construct(?DestinationConfig $config = null, ?DestinationRegistry $registry = null, ?JobRepository $jobs = null, ?ArchiveRepository $archives = null)
    {
        $this->registry = $registry ?? new DestinationRegistry;
        $this->config = $config ?? new DestinationConfig($this->registry);
        $this->jobs = $jobs ?? new JobRepository;
        $this->archives = $archives ?? new ArchiveRepository;
    }

    /**
     * @return DestinationConfig
     */
    public function config(): DestinationConfig
    {
        return $this->config;
    }

    /**
     * @return bool A destination is set.
     */
    public function configured(): bool
    {
        return $this->config->configured();
    }

    /**
     * @return Destination The stored destination, built with its decrypted secrets.
     * @throws DestinationFailure not_configured | unavailable | host_key_unknown | …
     */
    public function destination(): Destination
    {
        $current = $this->config->current();
        if ($current === null) {
            throw new DestinationFailure(DestinationFailure::NOT_CONFIGURED);
        }
        $fields = $current['fields'] + ['host_fingerprint' => $current['fingerprint']];

        return $this->registry->build($current['type'], $fields, $this->config->secrets());
    }

    /**
     * @return bool Read-back after writing is allowed (unknown = try).
     */
    public function canRead(): bool
    {
        return $this->config->report()?->capabilities->read ?? true;
    }

    /**
     * Queue the copy of a newly verified archive (called inside the transaction that marks it
     * "ok"). Archives brought in from elsewhere, and archives already copied, are not queued.
     *
     * @param string $archiveId
     * @return int|null Job id, null when nothing was queued.
     */
    public function queueNew(string $archiveId): ?int
    {
        if (!BackupFeature::enabled() || !$this->configured()) {
            return null;
        }
        $archive = $this->archives->find($archiveId);
        if ($archive === null || $archive->reason === Archive::REASON_IMPORT || $archive->offsite !== Archive::OFFSITE_NONE) {
            return null;
        }

        return $this->createJob($archiveId);
    }

    /**
     * Copy a verified archive (again) — after a failure, or one made before a destination was set.
     *
     * @param string $archiveId
     * @return int Job id.
     * @throws OffsiteRefused not_configured | not_found | not_ready | already_running
     */
    public function retry(string $archiveId): int
    {
        if (!BackupFeature::enabled() || !$this->configured()) {
            throw new OffsiteRefused(OffsiteRefused::NOT_CONFIGURED);
        }

        return DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($archiveId) {
            $archive = $this->archives->find($archiveId);
            if ($archive === null) {
                throw new OffsiteRefused(OffsiteRefused::NOT_FOUND);
            }
            // WHY not imported archives: only archives this site made go into its folder at the
            // destination (one brought in may be of unverified origin).
            if ($archive->status !== Archive::STATUS_OK || $archive->offsite === Archive::OFFSITE_DONE || $archive->reason === Archive::REASON_IMPORT) {
                throw new OffsiteRefused(OffsiteRefused::NOT_READY);
            }
            foreach ($this->jobs->activeFor($archiveId) as $job) {
                if ($job->type === Archive::JOB_UPLOAD) {
                    throw new OffsiteRefused(OffsiteRefused::ALREADY_RUNNING);
                }
            }

            return $this->createJob($archiveId);
        });
    }

    /**
     * Test a configuration as typed on the screen (blank secrets = the stored ones, when the
     * destination still points to the same place). Nothing is stored here.
     *
     * @param string                $type
     * @param array<string, mixed>  $fields
     * @param array<string, string> $typedSecrets
     * @return ConnectionReport
     * @throws OffsiteRefused unknown_type | unavailable | invalid
     */
    public function test(string $type, array $fields, #[\SensitiveParameter] array $typedSecrets): ConnectionReport
    {
        $resolved = $this->config->resolve($type, $fields, $typedSecrets);
        if (!$resolved['type']->available()) {
            throw new OffsiteRefused(OffsiteRefused::UNAVAILABLE);
        }
        $problems = $resolved['type']->factory::validate($resolved['fields'], $resolved['secrets']);
        if ($problems !== []) {
            throw new OffsiteRefused(OffsiteRefused::INVALID, $problems);
        }
        $current = $this->config->current();
        $pinned = $current !== null && $current['type'] === $type && $resolved['type']->sameIdentity($current['fields'], $resolved['fields']) ? $current['fingerprint'] : null;
        try {
            $destination = $this->registry->build($type, $resolved['fields'] + ['host_fingerprint' => $pinned], $resolved['secrets'], true);
        } catch (DestinationFailure $e) {
            return new ConnectionReport(new DestinationCapabilities(false, false, false, false), $e->reason, $e->fingerprint, time());
        }

        return (new ConnectionProbe)->run($destination, RemoteLayout::siteKey());
    }

    /**
     * Archives of this site found at the destination: names, sizes and times only.
     *
     * @return list<RemoteArchive> Newest first.
     * @throws DestinationFailure
     */
    public function remoteArchives(): array
    {
        $site = RemoteLayout::siteKey();
        $found = [];
        $modified = [];
        foreach ($this->destination()->files($site) as $file) {
            $parsed = RemoteLayout::parse($site, $file['path']);
            if ($parsed === null) {
                continue;
            }
            [$id, $name] = $parsed;
            $found[$id][$name] = max(0, (int) $file['size']);
            $modified[$id] = max($modified[$id] ?? 0, (int) ($file['modified'] ?? 0));
        }
        $list = [];
        foreach ($found as $id => $files) {
            $list[] = new RemoteArchive((string) $id, $files, ($modified[$id] ?? 0) ?: null, $this->archives->find((string) $id) !== null);
        }
        usort($list, fn (RemoteArchive $a, RemoteArchive $b) => strcmp($b->id, $a->id));

        return $list;
    }

    /**
     * @param string $archiveId
     * @return RemoteArchive|null
     * @throws DestinationFailure
     */
    public function remoteArchive(string $archiveId): ?RemoteArchive
    {
        foreach ($this->remoteArchives() as $archive) {
            if ($archive->id === $archiveId) {
                return $archive;
            }
        }

        return null;
    }

    /**
     * Forget the destination: waiting copies are cancelled, nothing is deleted anywhere.
     *
     * @return void
     */
    public function remove(): void
    {
        DB::connection(GP247_DB_CONNECTION)->transaction(function () {
            foreach ($this->jobs->activeOfType(Archive::JOB_UPLOAD) as $job) {
                $this->jobs->finish((int) $job->id, null, Archive::JOB_CANCELLED, 'upload: destination removed');
                if ($job->archive_id !== null) {
                    $this->archives->update((string) $job->archive_id, ['offsite' => Archive::OFFSITE_NONE]);
                }
            }
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'backup_archive')
                ->whereIn('offsite', [Archive::OFFSITE_PENDING, Archive::OFFSITE_FAILED])->update(['offsite' => Archive::OFFSITE_NONE]);
            $this->config->remove();
        });
    }

    /**
     * After a copy job ended as failed: the catalogue says so, the half-copied folder goes when the
     * credential allows it, the site owner is told. The local archive is left as it is.
     *
     * @param string|null $archiveId
     * @param string      $reason DestinationFailure reason (or a short error).
     * @return void
     */
    public function uploadFailed(?string $archiveId, string $reason): void
    {
        if ($archiveId === null) {
            return;
        }
        if ($this->archives->find($archiveId) !== null) {
            $this->archives->update($archiveId, ['offsite' => Archive::OFFSITE_FAILED]);
        }
        $cleaned = $this->dropPartial($archiveId);
        // WHY a fixed code: the runner's error text may carry server paths; the audit row and the
        // e-mail get only the reason code, the full text stays in the server log.
        // "upload: <code>" (ended by the step) or "DestinationFailure: <code>: …" (attempts used up).
        $code = preg_match('/^(?:upload|DestinationFailure|NotPermitted): ([a-z_]{1,40})(?::|$)/D', $reason, $m) === 1 ? $m[1] : DestinationFailure::ERROR;
        BackupAudit::record('offsite_upload', BackupAudit::FAILED, ['archive_id' => $archiveId, 'reason' => $code, 'status' => $cleaned]);
        BackupLog::failure('Off-site copy of archive ' . $archiveId . ' failed: ' . $reason . ' (partial copy: ' . $cleaned . ')');
        app(BackupNotifier::class)->offsiteFailed($archiveId, $code, $cleaned);
    }

    /**
     * @param string $archiveId
     * @return int Job id.
     */
    private function createJob(string $archiveId): int
    {
        $id = $this->jobs->create(Archive::JOB_UPLOAD, $archiveId, ['phase' => 'upload']);
        $this->archives->update($archiveId, ['offsite' => Archive::OFFSITE_PENDING]);

        return $id;
    }

    /**
     * Remove what a failed copy left at the destination, when that is allowed. A folder that has
     * its header is a complete copy (of an earlier run) and stays.
     *
     * @param string $archiveId
     * @return string cleaned | lifecycle (not allowed: the destination's lifecycle rule removes it) | none | unknown
     */
    private function dropPartial(string $archiveId): string
    {
        try {
            $destination = $this->destination();
            $site = RemoteLayout::siteKey();
            $paths = [];
            foreach ($destination->files($site . '/' . $archiveId) as $file) {
                $parsed = RemoteLayout::parse($site, $file['path']);
                if ($parsed !== null && $parsed[0] === $archiveId) {
                    if ($parsed[1] === RemoteLayout::HEADER) {
                        return 'none';
                    }
                    $paths[] = $file['path'];
                }
            }
            if ($paths === []) {
                return 'none';
            }
            foreach ($paths as $path) {
                $destination->delete($path);
            }

            return 'cleaned';
        } catch (NotPermitted $e) {
            return 'lifecycle';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
