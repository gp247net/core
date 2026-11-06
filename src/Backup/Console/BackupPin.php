<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\ArchiveActions;
use GP247\Core\Backup\Application\BackupAudit;
use GP247\Core\Backup\Console\Concerns\BackupCommandSupport;
use GP247\Core\Console\GP247Command;

/**
 * Pin a backup (never removed by the keep rule) or unpin it.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-cli
 */
class BackupPin extends GP247Command
{
    use BackupCommandSupport;

    /** @var string */
    protected $signature = 'gp247:backup-pin
        {archive : Archive id}
        {--unpin : Remove the pin}
        {--json}';

    /** @var string */
    protected $description = 'Pin or unpin a backup archive';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $id = (string) $this->argument('archive');
        $pinned = !(bool) $this->option('unpin');
        try {
            (new ArchiveActions)->pin($id, $pinned);
        } catch (\Throwable $e) {
            return $this->refusal($e);
        }
        BackupAudit::record($pinned ? 'pin' : 'unpin', BackupAudit::OK, ['archive_id' => $id]);
        if (!$this->isJson()) {
            $this->info('Archive ' . $id . ($pinned ? ' pinned.' : ' unpinned.'));
        }

        return $this->respondSuccess(['archive_id' => $id, 'pinned' => $pinned]);
    }
}
