<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Support\Facades\DB;

/**
 * Check again, pin and delete one archive — shared by the backup screen and the CLI (BK-5).
 * Checking again needs the backup password or recovery key: the data key of an archive can
 * only be unwrapped with it (backups are made without it).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-dashboard
 * @aidlc-adr backup-engine_tamper-evidence
 */
final class ArchiveActions
{
    private readonly ArchiveRepository $archives;
    private readonly JobRepository $jobs;
    private readonly LocalVault $vault;
    private readonly KeyService $keys;

    /**
     * @param ArchiveRepository|null $archives
     * @param JobRepository|null     $jobs
     * @param LocalVault|null        $vault
     * @param KeyService|null        $keys
     */
    public function __construct(?ArchiveRepository $archives = null, ?JobRepository $jobs = null, ?LocalVault $vault = null, ?KeyService $keys = null)
    {
        $this->archives = $archives ?? new ArchiveRepository;
        $this->jobs = $jobs ?? new JobRepository;
        $this->vault = $vault ?? app(LocalVault::class);
        $this->keys = $keys ?? new KeyService;
    }

    /**
     * Queue a full integrity check of a finished archive (the result lands on the catalogue row).
     *
     * @param string       $archiveId
     * @param BackupSecret $secret
     * @return int Job id.
     * @throws BackupRefused not_found | already_running
     * @throws \GP247\Core\Backup\Domain\ArchiveRejected When the header itself is refused (tampered, wrong secret).
     * @throws \GP247\Core\Backup\Domain\WrongBackupSecret
     */
    public function verify(string $archiveId, #[\SensitiveParameter] BackupSecret $secret): int
    {
        $archive = $this->finished($archiveId);
        $dir = $this->vault->archiveDir((string) $archive->id);
        $header = json_decode((string) @file_get_contents($dir . '/header.json'), true);
        $origin = ArchiveOrigin::of($archive, $dir, $this->keys)['origin'];
        $dek = ArchiveReader::dataKey($dir, $secret, $this->keys, $origin);

        return DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($archive, $header, $dek, $origin) {
            if ($this->jobs->activeFor((string) $archive->id) !== []) {
                throw new BackupRefused(BackupRefused::ALREADY_RUNNING);
            }

            return $this->jobs->create(Archive::JOB_VERIFY, (string) $archive->id, [
                'dek' => base64_encode($dek),
                'dek_wrapped' => is_array($header) ? ($header['dek'] ?? []) : [],
                'key_id' => is_array($header) ? (string) ($header['key_id'] ?? '') : '',
                'origin' => $origin,
            ]);
        });
    }

    /**
     * Open a finished archive with the backup password or recovery key, checked the way its
     * origin allows (an archive brought in from another site: data integrity only).
     *
     * @param string       $archiveId
     * @param BackupSecret $secret
     * @return ArchiveReader
     * @throws BackupRefused not_found
     * @throws \GP247\Core\Backup\Domain\ArchiveRejected
     * @throws \GP247\Core\Backup\Domain\WrongBackupSecret
     *
     * @aidlc-story US-backup-admin-cli, US-backup-engine-safe-format
     */
    public function open(string $archiveId, #[\SensitiveParameter] BackupSecret $secret): ArchiveReader
    {
        $archive = $this->finished($archiveId);
        $dir = $this->vault->archiveDir((string) $archive->id);

        return ArchiveReader::open($dir, $secret, $this->keys, ArchiveOrigin::of($archive, $dir, $this->keys)['origin']);
    }

    /**
     * @param string $archiveId
     * @param bool   $pinned Pinned archives are never removed by the keep rule.
     * @return void
     * @throws BackupRefused not_found
     */
    public function pin(string $archiveId, bool $pinned): void
    {
        $this->finished($archiveId);
        $this->archives->update($archiveId, ['pinned' => $pinned]);
    }

    /**
     * Remove an archive: its files first, then its finished jobs and its catalogue row. When the
     * files cannot be removed the row stays, so the backup is still listed and can be deleted
     * again once the folder permissions are fixed (never an invisible orphan folder).
     *
     * @param string $archiveId
     * @return void
     * @throws BackupRefused not_found | already_running (a job still uses it) | vault_delete_failed
     *
     * @aidlc-story US-backup-admin-dashboard, US-backup-admin-cli
     */
    public function delete(string $archiveId): void
    {
        $archive = $this->archives->find($archiveId);
        if ($archive === null) {
            throw new BackupRefused(BackupRefused::NOT_FOUND);
        }
        if ($this->jobs->activeFor($archiveId) !== [] || in_array($archiveId, $this->jobs->activeArchiveIds(Archive::JOB_RESTORE), true)) {
            throw new BackupRefused(BackupRefused::ALREADY_RUNNING);
        }
        if (!$this->vault->deleteArchive($archiveId)) {
            BackupLog::failure('Archive ' . $archiveId . ' was not deleted: its files could not be removed from the backup folder.');

            throw new BackupRefused(BackupRefused::VAULT_DELETE_FAILED);
        }
        $this->jobs->deleteFor($archiveId);
        $this->archives->delete($archiveId);
    }

    /**
     * @param string $archiveId
     * @return object Catalogue row of an archive that finished writing (ok or broken).
     * @throws BackupRefused
     */
    private function finished(string $archiveId): object
    {
        $archive = $this->archives->find($archiveId);
        if ($archive === null || !in_array($archive->status, [Archive::STATUS_OK, Archive::STATUS_BROKEN], true)) {
            throw new BackupRefused(BackupRefused::NOT_FOUND);
        }

        return $archive;
    }
}
