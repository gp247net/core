<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\NotPermitted;
use GP247\Core\Backup\Domain\RemoteLayout;

/**
 * Off-site side of retention (decision BK-6 #4): only the off-site copies of archives that local
 * retention just removed from the catalogue are deleted, and only when the credential may delete.
 * With a write-only credential nothing is deleted and the outcome says "skipped" — the
 * destination's lifecycle rule removes old copies. An archive at the destination that is not in
 * the catalogue is never touched (it may be the only copy left after an incident); removing an
 * archive by hand on the screen does not remove its off-site copy.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters
 */
final class OffsiteRetention
{
    private const MAX_PER_RUN = 20;

    /**
     * @param DestinationConfig $config
     */
    public function __construct(private readonly DestinationConfig $config)
    {
    }

    /**
     * @param Destination $destination
     * @param string      $siteKey
     * @return array{deleted: int, skipped: int}
     */
    public function run(Destination $destination, string $siteKey): array
    {
        $trash = $this->config->trash();
        if ($trash === []) {
            return ['deleted' => 0, 'skipped' => 0];
        }
        $deleted = 0;
        $skipped = 0;
        $handled = [];
        foreach (array_slice($trash, 0, self::MAX_PER_RUN) as $id) {
            try {
                foreach (iterator_to_array($destination->files($siteKey . '/' . $id), false) as $file) {
                    if (RemoteLayout::parse($siteKey, $file['path']) !== null) {
                        $destination->delete($file['path']);
                    }
                }
                $deleted++;
                $handled[] = $id;
            } catch (NotPermitted $e) {
                $skipped++;
                $handled[] = $id;
            } catch (\Throwable $e) {
                // Kept for the next run (network, …).
            }
        }
        $this->config->dropTrash($handled);
        $this->config->recordRetention($deleted, $skipped);
        if ($skipped > 0) {
            BackupAudit::record('offsite_retention', BackupAudit::REFUSED, ['reason' => 'write_only', 'status' => (string) $skipped]);
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }
}
