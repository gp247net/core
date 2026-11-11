<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Support\WriteActivity;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\PackFormat;
use GP247\Core\Backup\Domain\RemoteLayout;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Format\ResumableUnpacker;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use GP247\Core\Models\AdminConfig;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archive that is not in the catalogue into it (US-backup-restore-import-archive): a
 * `.gp247bak` file given on the command line, or one uploaded from the screen. Only the header is
 * unpacked here — enough to unwrap the data key with the password or recovery key of the site
 * that made the archive; the rest runs as an "import" job (ImportStep): unpack, signature or
 * "unverified origin", full integrity check, then the catalogue row. A file that fails any check
 * never enters the catalogue and its unpacked copy is removed.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive
 * @aidlc-adr backup-engine_tamper-evidence, backup-engine_archive-format
 */
final class ImportService
{
    /** Most files an archive at the external destination may have to be fetched back. */
    private const MAX_REMOTE_FILES = 20_000;

    private KeyService $keys;
    private LocalVault $vault;
    private ArchiveRepository $archives;
    private JobRepository $jobs;

    /**
     * @param KeyService|null        $keys
     * @param LocalVault|null        $vault
     * @param ArchiveRepository|null $archives
     * @param JobRepository|null     $jobs
     */
    public function __construct(?KeyService $keys = null, ?LocalVault $vault = null, ?ArchiveRepository $archives = null, ?JobRepository $jobs = null)
    {
        $this->keys = $keys ?? new KeyService;
        $this->vault = $vault ?? app(LocalVault::class);
        $this->archives = $archives ?? new ArchiveRepository;
        $this->jobs = $jobs ?? new JobRepository;
    }

    /**
     * @param string               $packFile `.gp247bak` file, or an archive folder of the vault without catalogue row.
     * @param bool                 $owned    The file belongs to the backup feature (an upload): removed when the import ends.
     * @param BackupSecret         $secret   Password or recovery key of the site that made the archive.
     * @param array<string, mixed> $options  created_by.
     * @return array{job_id: int, archive_id: string}
     * @throws RestoreRefused   feature_disabled | already_in_catalogue | already_running | disk_space
     * @throws ArchiveRejected  When the file is not an archive.
     * @throws \GP247\Core\Backup\Domain\WrongBackupSecret
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function start(string $packFile, bool $owned, #[\SensitiveParameter] BackupSecret $secret, array $options = []): array
    {
        if (!BackupFeature::enabled()) {
            throw new RestoreRefused(RestoreRefused::FEATURE_DISABLED);
        }
        if (is_dir($packFile)) {
            return $this->startFromFolder($packFile, $secret, $options);
        }
        if (!is_file($packFile) || is_link($packFile)) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'not a file');
        }
        $this->assertSpace((int) filesize($packFile));
        $dir = $this->vault->incomingDir() . DIRECTORY_SEPARATOR . 'x' . bin2hex(random_bytes(8));
        try {
            // Only the first entry (the header): small, enough to unwrap the data key now, so the
            // job never has to keep the password between slices.
            $state = ResumableUnpacker::step($packFile, $dir, [], fn (array $s) => !in_array(PackFormat::HEADER_NAME, (array) $s['written'], true));
            $peek = ArchiveReader::peek($dir);
            if ($this->archives->find($peek['archive_id']) !== null || is_dir($this->vault->archiveDir($peek['archive_id']))) {
                throw new RestoreRefused(RestoreRefused::ALREADY_IN_CATALOGUE, $peek['archive_id'], ['archive_id' => $peek['archive_id']]);
            }
            $opened = ArchiveReader::dataKeyFromHeader($dir, $secret, $this->keys);
            $cursor = [
                'phase' => 'unpack',
                'source' => $packFile,
                'owned' => $owned,
                'dir' => $dir,
                'unpack' => $state,
                'archive_id' => $peek['archive_id'],
                'dek' => base64_encode($opened['dek']),
                'dek_wrapped' => $opened['header']['dek'],
                'key_id' => $opened['header']['key_id'],
                'started' => now()->getTimestamp(),
                'step' => 0,
                'created_by' => isset($options['created_by']) ? mb_substr((string) $options['created_by'], 0, 100) : null,
            ];
            $jobId = $this->locked(fn () => $this->jobs->create(Archive::JOB_IMPORT, null, $cursor));
        } catch (\Throwable $e) {
            ResumableUnpacker::clean($dir);
            // WHY only these: a wrong password or a busy moment keeps the upload for another try.
            if ($owned && ($e instanceof ArchiveRejected || ($e instanceof RestoreRefused && $e->reason === RestoreRefused::ALREADY_IN_CATALOGUE))) {
                @unlink($packFile);
            }

            throw $e;
        }

        return ['job_id' => $jobId, 'archive_id' => $peek['archive_id']];
    }

    /**
     * Bring an archive back from the external destination (US-backup-storage-fetch-remote): its
     * header comes now — enough to unwrap the data key with the password or recovery key, so the
     * job never keeps the password — and the import job copies the other files in slices ("fetch"
     * phase), then checks the signature and every hash before the archive enters the catalogue.
     *
     * @param string               $archiveId
     * @param BackupSecret         $secret
     * @param array<string, mixed> $options created_by.
     * @param OffsiteService|null  $offsite
     * @return array{job_id: int, archive_id: string}
     * @throws RestoreRefused  feature_disabled | already_in_catalogue | not_found | already_running | disk_space
     * @throws ArchiveRejected When the header is not an archive header.
     * @throws \GP247\Core\Backup\Domain\WrongBackupSecret
     * @throws \GP247\Core\Backup\Domain\DestinationFailure
     *
     * @aidlc-unit backup-storage
     * @aidlc-story US-backup-storage-fetch-remote
     */
    public function startFromRemote(string $archiveId, #[\SensitiveParameter] BackupSecret $secret, array $options = [], ?OffsiteService $offsite = null): array
    {
        if (!BackupFeature::enabled()) {
            throw new RestoreRefused(RestoreRefused::FEATURE_DISABLED);
        }
        if (!RemoteLayout::isArchiveId($archiveId)) {
            throw new RestoreRefused(RestoreRefused::NOT_FOUND, 'archive id');
        }
        if ($this->archives->find($archiveId) !== null || is_dir($this->vault->archiveDir($archiveId))) {
            throw new RestoreRefused(RestoreRefused::ALREADY_IN_CATALOGUE, $archiveId, ['archive_id' => $archiveId]);
        }
        $offsite ??= new OffsiteService(null, null, $this->jobs, $this->archives);
        $remote = $offsite->remoteArchive($archiveId);
        if ($remote === null || !$remote->complete()) {
            throw new RestoreRefused(RestoreRefused::NOT_FOUND, 'not at the destination');
        }
        // WHY a ceiling: the file list comes from the destination, not from the signed manifest
        // (checked only after the copy); a tampered destination must not make the job download
        // an endless list. 20 000 parts of 16 MiB is far beyond any real archive.
        if (count($remote->files) > self::MAX_REMOTE_FILES) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'too many files at the destination');
        }
        if ($remote->files[RemoteLayout::HEADER] > ArchiveReader::MAX_HEADER_BYTES) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'header size');
        }
        $this->assertSpace($remote->bytes());
        $site = RemoteLayout::siteKey();
        $dir = $this->vault->incomingDir() . DIRECTORY_SEPARATOR . 'r' . bin2hex(random_bytes(8));
        try {
            if (!@mkdir($dir, 0700) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create the fetch folder.');
            }
            $header = $offsite->destination()->read(RemoteLayout::path($site, $archiveId, RemoteLayout::HEADER));
            $bytes = (string) stream_get_contents($header, ArchiveReader::MAX_HEADER_BYTES + 1);
            fclose($header);
            if (strlen($bytes) !== $remote->files[RemoteLayout::HEADER] || file_put_contents($dir . DIRECTORY_SEPARATOR . RemoteLayout::HEADER, $bytes) === false) {
                throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'header size');
            }
            if (ArchiveReader::peek($dir)['archive_id'] !== $archiveId) {
                throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'archive id');
            }
            $opened = ArchiveReader::dataKeyFromHeader($dir, $secret, $this->keys);
            $files = $remote->files;
            unset($files[RemoteLayout::HEADER]);
            $cursor = [
                'phase' => 'fetch',
                'source' => null,
                'owned' => false,
                'remote' => true,
                'dir' => $dir,
                'fetch' => ['files' => $files, 'order' => RemoteLayout::uploadOrder(array_keys($files)), 'i' => 0, 'site' => $site],
                'archive_id' => $archiveId,
                'dek' => base64_encode($opened['dek']),
                'dek_wrapped' => $opened['header']['dek'],
                'key_id' => $opened['header']['key_id'],
                'started' => now()->getTimestamp(),
                'step' => 0,
                'created_by' => isset($options['created_by']) ? mb_substr((string) $options['created_by'], 0, 100) : null,
            ];
            $jobId = $this->locked(fn () => $this->jobs->create(Archive::JOB_IMPORT, null, $cursor));
        } catch (\Throwable $e) {
            ResumableUnpacker::clean($dir);

            throw $e;
        }

        return ['job_id' => $jobId, 'archive_id' => $archiveId];
    }

    /**
     * Catalogue an archive folder already inside the vault that has no catalogue row (the
     * database lost it, or an import died between moving the folder and writing the row). Same
     * checks as an import; the folder is never moved nor removed.
     *
     * @param string               $dir
     * @param BackupSecret         $secret
     * @param array<string, mixed> $options
     * @return array{job_id: int, archive_id: string}
     * @throws RestoreRefused already_in_catalogue | already_running | not_found
     * @throws ArchiveRejected
     */
    private function startFromFolder(string $dir, #[\SensitiveParameter] BackupSecret $secret, array $options): array
    {
        $id = basename(rtrim(str_replace('\\', '/', $dir), '/'));
        $real = realpath($dir);
        if (preg_match('/^[0-9A-Z]{26}$/D', $id) !== 1 || $real === false || $real !== realpath($this->vault->archiveDir($id))) {
            throw new RestoreRefused(RestoreRefused::NOT_FOUND, 'not an archive folder of the backup folder');
        }
        if ($this->archives->find($id) !== null) {
            throw new RestoreRefused(RestoreRefused::ALREADY_IN_CATALOGUE, $id, ['archive_id' => $id]);
        }
        if (ArchiveReader::peek($real)['archive_id'] !== $id) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'folder name');
        }
        $opened = ArchiveReader::dataKeyFromHeader($real, $secret, $this->keys);
        $cursor = [
            'phase' => 'open',
            'source' => null,
            'owned' => false,
            'in_vault' => true,
            'dir' => $real,
            'archive_id' => $id,
            'dek' => base64_encode($opened['dek']),
            'dek_wrapped' => $opened['header']['dek'],
            'key_id' => $opened['header']['key_id'],
            'started' => now()->getTimestamp(),
            'step' => 0,
            'created_by' => isset($options['created_by']) ? mb_substr((string) $options['created_by'], 0, 100) : null,
        ];

        return ['job_id' => $this->locked(fn () => $this->jobs->create(Archive::JOB_IMPORT, null, $cursor)), 'archive_id' => $id];
    }

    /**
     * @param int $jobId
     * @return array{status: string, archive_id: string|null, origin: string|null, error: string|null}
     *         status: running | done | failed | cancelled.
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public function result(int $jobId): array
    {
        $job = $this->jobs->find($jobId);
        if ($job === null || $job->type !== Archive::JOB_IMPORT) {
            return ['status' => 'failed', 'archive_id' => null, 'origin' => null, 'error' => 'not_found'];
        }
        $archive = $job->archive_id === null ? null : $this->archives->find((string) $job->archive_id);

        return [
            'status' => in_array($job->status, Archive::JOB_ACTIVE, true) ? 'running' : (string) $job->status,
            'archive_id' => $archive === null ? null : (string) $archive->id,
            'origin' => $archive === null ? null : (string) $archive->origin,
            'error' => $job->error === null ? null : (string) $job->error,
        ];
    }

    /**
     * @param int $bytes Size of the packed file (the unpacked archive is about as large).
     * @return void
     * @throws RestoreRefused disk_space
     */
    private function assertSpace(int $bytes): void
    {
        $free = $this->vault->freeBytes();
        if ($free !== null && $free < $bytes + 50 * 1024 * 1024) {
            throw new RestoreRefused(RestoreRefused::DISK_SPACE, '', ['free' => $free, 'needed' => $bytes]);
        }
    }

    /**
     * One job that writes at a time (same lock as BackupService::create() / RestoreService).
     *
     * @param callable $create
     * @return int
     * @throws RestoreRefused already_running
     */
    private function locked(callable $create): int
    {
        return DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($create) {
            AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', BackupFeature::CONFIG_KEY)->lockForUpdate()->first();
            if ($this->jobs->hasActiveWriteJob()) {
                throw new RestoreRefused(RestoreRefused::ALREADY_RUNNING);
            }
            // US-backup-engine-write-activity-exclusion: an extension writing in bulk (data import).
            if (($other = WriteActivity::busy(WriteProbe::KEY)) !== null) {
                throw new RestoreRefused(RestoreRefused::BUSY_ELSEWHERE, $other['key'], ['activity' => $other['label']]);
            }

            return $create();
        });
    }
}
