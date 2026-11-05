<?php

namespace GP247\Core\Backup\Infrastructure\Store;

use GP247\Core\Backup\Domain\Archive;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rows of backup_archive — the catalogue of archives on this site.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-retention
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class ArchiveRepository
{
    /**
     * @param ConnectionInterface|null $db Connection holding backup_archive (null = GP247 connection).
     */
    public function __construct(private ?ConnectionInterface $db = null)
    {
    }

    /**
     * @param array<string, mixed> $values Columns (id, kind, reason, scope, key_id…).
     * @return void
     */
    public function create(array $values): void
    {
        $now = now();
        $this->table()->insert($values + [
            'status' => Archive::STATUS_RUNNING,
            'origin' => 'site',
            'offsite' => Archive::OFFSITE_NONE,
            'pinned' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param string $id
     * @return object|null
     */
    public function find(string $id): ?object
    {
        return $this->table()->where('id', $id)->first();
    }

    /**
     * @param string               $id
     * @param array<string, mixed> $values
     * @return void
     */
    public function update(string $id, array $values): void
    {
        $this->table()->where('id', $id)->update($values + ['updated_at' => now()]);
    }

    /**
     * @param string $id
     * @return bool True when the archive was still in progress and is now cancelled.
     */
    public function markCancelled(string $id): bool
    {
        return $this->table()->where('id', $id)
            ->whereIn('status', [Archive::STATUS_RUNNING, Archive::STATUS_VERIFYING])
            ->update(['status' => Archive::STATUS_CANCELLED, 'error' => 'cancelled', 'updated_at' => now()]) === 1;
    }

    /**
     * @return list<object> Successful archives, newest first.
     */
    public function okNewestFirst(): array
    {
        return $this->table()->where('status', Archive::STATUS_OK)
            ->orderByDesc('created_at')->orderByDesc('id')->get()->all();
    }

    /**
     * @param string $id
     * @return void
     */
    public function delete(string $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function table()
    {
        $this->db ??= DB::connection(GP247_DB_CONNECTION);

        return $this->db->table(GP247_DB_PREFIX . 'backup_archive');
    }
}
