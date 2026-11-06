<?php

namespace GP247\Core\Backup\Console;

use GP247\Core\Backup\Application\PostIncidentChecklist;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Console\GP247Command;

/**
 * List the backups of this site: the catalogue, and the archive folders found in the vault that
 * the catalogue does not know (a damaged database lost them — their header is read without any
 * password, nothing about them is trusted until they are imported or restored).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-cli
 */
class BackupList extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:backup-list {--json}';

    /** @var string */
    protected $description = 'List backup archives (catalogue and archives found in the backup folder)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $vault = app(LocalVault::class);
        $rows = [];
        $known = [];
        try {
            foreach ((new ArchiveRepository)->newestFirst() as $archive) {
                $known[(string) $archive->id] = true;
                $rows[] = [
                    'id' => (string) $archive->id,
                    'kind' => (string) $archive->kind,
                    'reason' => (string) $archive->reason,
                    'status' => (string) $archive->status,
                    'origin' => (string) $archive->origin,
                    'consistency' => $archive->consistency === null ? null : (string) $archive->consistency,
                    'pinned' => (bool) $archive->pinned,
                    'size' => (int) $archive->size,
                    'created_at' => (string) $archive->created_at,
                    'verified_at' => $archive->verified_at === null ? null : (string) $archive->verified_at,
                ];
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $this->addWarning('The backup catalogue cannot be read; only the backup folder is listed.');
        }
        $uncatalogued = [];
        foreach ($vault->archiveIds() as $id) {
            if (isset($known[$id])) {
                continue;
            }
            try {
                $uncatalogued[] = ['id' => $id] + ArchiveReader::peek($vault->archiveDir($id));
            } catch (ArchiveRejected $e) {
                $uncatalogued[] = ['id' => $id, 'error' => $e->reason];
            }
        }
        $pending = PostIncidentChecklist::pending();

        if (!$this->isJson()) {
            $this->table(['Id', 'Kind', 'Reason', 'Status', 'Origin', 'Pinned', 'Size', 'Created'], array_map(fn ($r) => [
                $r['id'], $r['kind'], $r['reason'], $r['status'], $r['origin'], $r['pinned'] ? 'yes' : '', $r['size'], $r['created_at'],
            ], $rows));
            foreach ($uncatalogued as $entry) {
                $this->warn('Not in the catalogue: ' . $entry['id'] . (isset($entry['created_at']) ? ' (' . $entry['created_at'] . ')' : ' (header unreadable)') . ' — catalogue it with: php artisan gp247:backup-import ' . app(LocalVault::class)->archiveDir($entry['id']));
            }
            if ($pending !== null) {
                $this->warn('After the post-incident restore some tasks are still to be done by hand (see the backup screen or gp247:doctor).');
            }
        }

        return $this->respondSuccess([
            'archives' => $rows,
            'uncatalogued' => $uncatalogued,
            'post_incident_pending' => $pending !== null,
        ]);
    }
}
