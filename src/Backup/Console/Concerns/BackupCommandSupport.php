<?php

namespace GP247\Core\Backup\Console\Concerns;

use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupSecret;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Domain\WrongBackupSecret;
use GP247\Core\Backup\Infrastructure\Install\BackupSchema;
use Illuminate\Support\Facades\Schema;

/**
 * What the backup commands share (US-backup-admin-cli, US-backup-restore-cli-restore):
 *
 *  - the backup password / recovery key is asked for interactively (hidden), or read as ONE line
 *    from standard input with --secret-stdin in a script — never a command-line argument or an
 *    environment variable (process lists, shell history and CI logs show those);
 *  - a destructive step run without a person (no interaction, or --json) needs
 *    --confirm=<archive id>, the id typed again — not a generic --force;
 *  - refusals become stable error codes in the --json envelope.
 *
 * Commands using it declare {--secret-stdin} {--recovery-key} {--confirm=} as they need.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-cli, US-backup-restore-cli-restore
 */
trait BackupCommandSupport
{
    /**
     * @return bool A person can answer questions (not --no-interaction, not --json).
     */
    protected function interactive(): bool
    {
        return $this->input->isInteractive() && !$this->isJson();
    }

    /**
     * @param string $question
     * @return BackupSecret|null Null when none was given (the caller reports it).
     */
    protected function readSecret(string $question = 'Backup password or recovery key'): ?BackupSecret
    {
        $recovery = $this->hasOption('recovery-key') && (bool) $this->option('recovery-key');
        if ($this->hasOption('secret-stdin') && (bool) $this->option('secret-stdin')) {
            $stream = BackupStdin::stream();
            $line = $stream === null ? false : fgets($stream);
            $value = $line === false ? '' : rtrim($line, "\r\n");
        } elseif ($this->interactive()) {
            $value = (string) $this->secret($question);
        } else {
            return null;
        }
        if ($value === '') {
            return null;
        }

        return $recovery ? BackupSecret::recovery($value) : BackupSecret::password($value);
    }

    /**
     * @param string $archiveId
     * @param string $what      What is about to happen (shown in the question).
     * @return bool The person typed the id again, or --confirm carried exactly that id.
     */
    protected function confirmArchive(string $archiveId, string $what): bool
    {
        if ($this->interactive()) {
            $typed = (string) $this->ask($what . ' — type the archive id ' . $archiveId . ' to confirm');

            return hash_equals($archiveId, trim($typed));
        }

        return $this->hasOption('confirm') && is_string($this->option('confirm')) && hash_equals($archiveId, (string) $this->option('confirm'));
    }

    /**
     * Run a job to its end. When a browser tick holds it just now ("busy"), wait for that slice
     * and go on, instead of reporting a failure while the job is in fact running.
     *
     * @param int $jobId
     * @return array See JobRunner::runSlice().
     */
    protected function runJob(int $jobId): array
    {
        $runner = new \GP247\Core\Backup\Application\JobRunner;
        for ($try = 0; $try < 60; $try++) {
            $result = $runner->runToEnd($jobId);
            if (!in_array($result['status'], ['busy', 'error', 'running'], true)) {
                return $result;
            }
            if ($result['status'] === 'busy') {
                sleep(2);
            }
        }

        return $result;
    }

    /**
     * The backup feature's tables, created when missing (a damaged site may have lost them).
     *
     * @return void
     */
    protected function ensureBackupTables(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        if (!$schema->hasTable(GP247_DB_PREFIX . 'backup_archive') || !$schema->hasTable(GP247_DB_PREFIX . 'backup_job')) {
            BackupSchema::create($schema);
            $this->addWarning('The backup tables were missing and have been created again.');
        }
    }

    /**
     * @param \Throwable $e
     * @return int Failure envelope for the refusals the backup feature raises; rethrows others.
     * @throws \Throwable
     */
    protected function refusal(\Throwable $e): int
    {
        return match (true) {
            $e instanceof WrongBackupSecret => $this->respondFailure('wrong_secret', 'The backup password or recovery key does not open this archive.'),
            $e instanceof ArchiveRejected => $this->respondFailure('archive_rejected', 'The archive was rejected: ' . $e->reason . '.', ['reason' => $e->reason]),
            $e instanceof RestoreRefused => $this->respondFailure($e->reason, 'Refused: ' . $e->reason . '.', self::numbers($e->context)),
            $e instanceof BackupRefused => $this->respondFailure($e->reason, 'Refused: ' . $e->reason . '.', self::numbers($e->context)),
            default => throw $e,
        };
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, int> Numbers only (no paths, no names).
     */
    private static function numbers(array $context): array
    {
        $out = [];
        foreach (['free', 'needed', 'limit', 'received'] as $key) {
            if (isset($context[$key]) && is_numeric($context[$key])) {
                $out[$key] = (int) $context[$key];
            }
        }

        return $out;
    }
}
