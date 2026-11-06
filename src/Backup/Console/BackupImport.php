<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\ImportService;
use GP247\Core\Backup\Console\Concerns\BackupCommandSupport;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Console\GP247Command;

/**
 * Bring a `.gp247bak` file into the catalogue (downloaded earlier, copied from another server,
 * fetched from an external store). It is opened with the password or recovery key of the site
 * that made it and checked in full first; the file given is never changed or removed.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive
 * @aidlc-adr backup-engine_tamper-evidence
 */
class BackupImport extends GP247Command
{
    use BackupCommandSupport;

    /** @var string */
    protected $signature = 'gp247:backup-import
        {file : Path of the .gp247bak file (or an archive folder of the backup folder that lost its catalogue row)}
        {--secret-stdin : Read the backup password (or recovery key) from one line of standard input}
        {--recovery-key : The secret given is the recovery key}
        {--json}';

    /** @var string */
    protected $description = 'Import a .gp247bak backup file into the catalogue after checking it';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $file = realpath((string) $this->argument('file'));
        if ($file === false || (!is_file($file) && !is_dir($file))) {
            return $this->respondFailure('file_not_found', 'The file does not exist.');
        }
        $this->ensureBackupTables();
        $secret = $this->readSecret('Password or recovery key of the site that made this backup');
        if ($secret === null) {
            return $this->respondFailure('secret_required', 'The backup password or recovery key is required (interactive, or --secret-stdin).');
        }
        $result = self::import($file, $secret, fn (int $jobId) => $this->runJob($jobId));
        if ($result instanceof \Throwable) {
            return $this->refusal($result);
        }
        if ($result['status'] !== 'done') {
            return $this->respondFailure('import_failed', 'The file was not imported: ' . ($result['error'] ?? 'unknown') . '.', $result);
        }
        if ($result['origin'] === ArchiveReader::ORIGIN_UNVERIFIED) {
            $this->addWarning('This archive was not made by this site (or its signing key is lost): its origin cannot be verified. Restoring it asks for a separate confirmation and never restores code or .env.');
        }
        if (!$this->isJson()) {
            $this->info('Imported as ' . $result['archive_id'] . ' (origin: ' . $result['origin'] . ').');
        }

        return $this->respondSuccess($result);
    }

    /**
     * Shared with gp247:backup-restore --file.
     *
     * @param string                                  $file   `.gp247bak` file, or an archive folder of the backup folder without catalogue row.
     * @param \GP247\Core\Backup\Domain\BackupSecret $secret
     * @param callable(int): array                    $run    Runs the job to its end.
     * @return array{status: string, archive_id: string|null, origin: string|null, error: string|null}|\Throwable
     */
    public static function import(string $file, #[\SensitiveParameter] \GP247\Core\Backup\Domain\BackupSecret $secret, callable $run): array|\Throwable
    {
        try {
            $service = new ImportService;
            $started = $service->start($file, false, $secret, ['created_by' => 'cli']);
            $run($started['job_id']);

            return $service->result($started['job_id']);
        } catch (\Throwable $e) {
            return $e;
        }
    }
}
