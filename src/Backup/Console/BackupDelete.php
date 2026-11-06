<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\ArchiveActions;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Console\Concerns\BackupCommandSupport;
use GP247\Core\Console\GP247Command;

/**
 * Delete a backup (its files and catalogue row). A person types the archive id again; a script
 * passes it as --confirm=<archive id>.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-cli
 */
class BackupDelete extends GP247Command
{
    use BackupCommandSupport;

    /** @var string */
    protected $signature = 'gp247:backup-delete
        {archive : Archive id}
        {--confirm= : The archive id again (required without interaction)}
        {--json}';

    /** @var string */
    protected $description = 'Delete a backup archive';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $id = (string) $this->argument('archive');
        if (!$this->confirmArchive($id, 'Delete this backup for good')) {
            BackupAudit::record('delete', BackupAudit::REFUSED, ['archive_id' => $id, 'reason' => 'not_confirmed']);

            return $this->respondFailure('confirmation_required', 'Not deleted: confirm with the archive id (interactively, or --confirm=<archive id>).');
        }
        try {
            (new ArchiveActions)->delete($id);
        } catch (\Throwable $e) {
            BackupAudit::record('delete', BackupAudit::REFUSED, ['archive_id' => $id]);

            return $this->refusal($e);
        }
        BackupAudit::record('delete', BackupAudit::OK, ['archive_id' => $id]);
        if (!$this->isJson()) {
            $this->info('Archive ' . $id . ' deleted.');
        }

        return $this->respondSuccess(['archive_id' => $id, 'deleted' => true]);
    }
}
