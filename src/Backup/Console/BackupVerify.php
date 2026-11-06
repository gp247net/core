<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\ArchiveActions;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Application\JobRunner;
use GP247\Core\Backup\Console\Concerns\BackupCommandSupport;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Console\GP247Command;

/**
 * Check a backup again from the first byte to the last (every part, frame and segment). Needs
 * the backup password or recovery key: asked for, or one line on standard input (--secret-stdin).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-cli
 * @aidlc-adr backup-engine_tamper-evidence
 */
class BackupVerify extends GP247Command
{
    use BackupCommandSupport;

    /** @var string */
    protected $signature = 'gp247:backup-verify
        {archive : Archive id}
        {--secret-stdin : Read the backup password (or recovery key) from one line of standard input}
        {--recovery-key : The secret given is the recovery key}
        {--json}';

    /** @var string */
    protected $description = 'Check the integrity of a backup archive';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $id = (string) $this->argument('archive');
        $secret = $this->readSecret();
        if ($secret === null) {
            return $this->respondFailure('secret_required', 'The backup password or recovery key is required (interactive, or --secret-stdin).');
        }
        try {
            $jobId = (new ArchiveActions)->verify($id, $secret);
            (new JobRunner)->runToEnd($jobId);
        } catch (\Throwable $e) {
            BackupAudit::record('verify', BackupAudit::REFUSED, ['archive_id' => $id]);

            return $this->refusal($e);
        }
        $archive = (new ArchiveRepository)->find($id);
        $ok = ($archive->status ?? null) === Archive::STATUS_OK;
        BackupAudit::record('verify', $ok ? BackupAudit::OK : BackupAudit::FAILED, ['archive_id' => $id, 'job_id' => $jobId]);
        $data = ['archive_id' => $id, 'status' => $archive->status ?? null, 'origin' => $archive->origin ?? null];
        if (!$ok) {
            return $this->respondFailure('archive_broken', 'The archive failed its check: ' . ($archive->error ?? 'unknown') . '.', $data);
        }
        if (!$this->isJson()) {
            $this->info('Archive ' . $id . ' checked: every part and segment is intact.');
        }

        return $this->respondSuccess($data);
    }
}
