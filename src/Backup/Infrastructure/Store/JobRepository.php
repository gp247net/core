<?php

namespace GP247\Core\Backup\Infrastructure\Store;

use DateTimeInterface;
use GP247\Core\Backup\Domain\Archive;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rows of backup_job: the work queue of the sliced runner, with a lease taken by ONE
 * conditional UPDATE (no cache lock — file caches are not reliably atomic). The cursor may
 * hold an archive data key, so it is stored encrypted with gp247_secret_encrypt() and cleared
 * when the job ends. Every write made while running is guarded by the lease owner, so a slice
 * that lost its lease (expired, or job cancelled) cannot overwrite newer state.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class JobRepository
{
    /**
     * @param ConnectionInterface|null $db Connection holding backup_job (null = GP247 connection).
     */
    public function __construct(private ?ConnectionInterface $db = null)
    {
    }

    /**
     * @param string                    $type      Archive::JOB_*.
     * @param string|null               $archiveId
     * @param array<string, mixed>|null $cursor    Initial cursor (stored encrypted).
     * @return int Job id.
     */
    public function create(string $type, ?string $archiveId, #[\SensitiveParameter] ?array $cursor = null): int
    {
        $now = now();

        return (int) $this->table()->insertGetId([
            'type' => $type,
            'archive_id' => $archiveId,
            'status' => Archive::JOB_PENDING,
            'cursor' => $cursor === null ? null : self::seal($cursor),
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param int $id
     * @return object|null Row.
     */
    public function find(int $id): ?object
    {
        return $this->table()->where('id', $id)->first();
    }

    /**
     * Take the lease of an active job whose lease is free or expired. A lease that was never
     * released (the request holding it died) counts as one failed attempt.
     *
     * @param int               $id
     * @param string            $owner Random id of this slice.
     * @param DateTimeInterface $until Lease end.
     * @return bool True when this slice now owns the job.
     */
    public function acquire(int $id, string $owner, DateTimeInterface $until): bool
    {
        $now = now();

        return $this->table()
            ->where('id', $id)
            ->whereIn('status', Archive::JOB_ACTIVE)
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->update([
                // WHY first: MySQL evaluates SET left to right with already-updated values, so
                // this must read lease_owner before it is overwritten below.
                'attempts' => DB::raw('attempts + (CASE WHEN lease_owner IS NULL THEN 0 ELSE 1 END)'),
                'lease_owner' => $owner,
                'lease_until' => $until,
                'status' => Archive::JOB_RUNNING,
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * @param object $job Row.
     * @return array<string, mixed>
     * @throws RuntimeException When the cursor cannot be decrypted (encryption key changed).
     */
    public function cursor(object $job): array
    {
        if ($job->cursor === null || $job->cursor === '') {
            return [];
        }
        try {
            $data = json_decode(gp247_secret_decrypt((string) $job->cursor), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('The progress of this backup job cannot be read.');
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param int                  $id
     * @param string               $owner
     * @param array<string, mixed> $cursor
     * @param bool                 $progress Work was done since the last save (resets attempts).
     * @param DateTimeInterface|null $until  New lease end (long CLI runs keep their lease alive).
     * @return bool False when the lease was lost — the slice must stop.
     */
    public function saveCursor(int $id, string $owner, #[\SensitiveParameter] array $cursor, bool $progress = true, ?DateTimeInterface $until = null): bool
    {
        $values = ['cursor' => self::seal($cursor), 'updated_at' => now()];
        if ($progress) {
            $values['attempts'] = 0;
        }
        if ($until !== null) {
            $values['lease_until'] = $until;
        }

        return $this->owned($id, $owner)->update($values) === 1;
    }

    /**
     * @param int    $id
     * @param string $owner
     * @return void
     */
    public function release(int $id, string $owner): void
    {
        $this->owned($id, $owner)->update(['lease_owner' => null, 'lease_until' => null, 'updated_at' => now()]);
    }

    /**
     * @param int    $id
     * @param string $owner
     * @param string $message Short, secret-free.
     * @return int|null Attempts after this error; null when this slice no longer owned the job.
     */
    public function recordError(int $id, string $owner, string $message): ?int
    {
        $updated = $this->owned($id, $owner)->update([
            'attempts' => DB::raw('attempts + 1'),
            'error' => mb_substr($message, 0, 255),
            'lease_owner' => null,
            'lease_until' => null,
            'updated_at' => now(),
        ]);

        return $updated === 1 ? (int) $this->table()->where('id', $id)->value('attempts') : null;
    }

    /**
     * End a job (done / failed / cancelled); the cursor and its data key are dropped.
     *
     * @param int         $id
     * @param string|null $owner Lease owner, or null to end it regardless (cancel).
     * @param string      $status
     * @param string|null $error
     * @return bool
     */
    public function finish(int $id, ?string $owner, string $status, ?string $error = null): bool
    {
        $query = $owner === null ? $this->table()->where('id', $id) : $this->owned($id, $owner);
        $values = ['status' => $status, 'cursor' => null, 'lease_owner' => null, 'lease_until' => null, 'updated_at' => now()];
        if ($error !== null) {
            $values['error'] = mb_substr($error, 0, 255);
        }

        return $query->update($values) === 1;
    }

    /**
     * Record which archive a job produced (an import learns its archive id only at the end).
     *
     * @param int    $id
     * @param string $owner Lease owner.
     * @param string $archiveId
     * @return bool
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-import-archive
     */
    public function attachArchive(int $id, string $owner, string $archiveId): bool
    {
        return $this->owned($id, $owner)->update(['archive_id' => $archiveId, 'updated_at' => now()]) === 1;
    }

    /**
     * @return bool A job that writes (backup / restore / import) is pending or running.
     */
    public function hasActiveWriteJob(): bool
    {
        return $this->table()->whereIn('type', Archive::WRITE_JOBS)->whereIn('status', Archive::JOB_ACTIVE)->exists();
    }

    /**
     * @param string $type Archive::JOB_*.
     * @return list<string> Archives that active jobs of this type work on.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-table-restore
     */
    public function activeArchiveIds(string $type): array
    {
        return $this->table()->where('type', $type)->whereIn('status', Archive::JOB_ACTIVE)->whereNotNull('archive_id')
            ->pluck('archive_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    /**
     * @param string $type Archive::JOB_*.
     * @return list<object> Active jobs of this type, oldest first.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-full-database
     */
    public function activeOfType(string $type): array
    {
        return $this->table()->where('type', $type)->whereIn('status', Archive::JOB_ACTIVE)->orderBy('id')->get()->all();
    }

    /**
     * @return object|null Oldest pending / running job.
     */
    public function nextActive(): ?object
    {
        return $this->table()->whereIn('status', Archive::JOB_ACTIVE)->orderBy('id')->first();
    }

    /**
     * @param string $archiveId
     * @return list<object> Active jobs of an archive.
     */
    public function activeFor(string $archiveId): array
    {
        return $this->table()->where('archive_id', $archiveId)->whereIn('status', Archive::JOB_ACTIVE)->orderBy('id')->get()->all();
    }

    /**
     * @param string $archiveId
     * @return void
     */
    public function deleteFor(string $archiveId): void
    {
        $this->table()->where('archive_id', $archiveId)->whereNotIn('status', Archive::JOB_ACTIVE)->delete();
    }

    /**
     * @param int    $id
     * @param string $owner
     * @return \Illuminate\Database\Query\Builder
     */
    private function owned(int $id, string $owner)
    {
        return $this->table()->where('id', $id)->where('lease_owner', $owner)->where('status', Archive::JOB_RUNNING);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function table()
    {
        $this->db ??= DB::connection(GP247_DB_CONNECTION);

        return $this->db->table(GP247_DB_PREFIX . 'backup_job');
    }

    /**
     * @param array<string, mixed> $cursor
     * @return string
     */
    private static function seal(#[\SensitiveParameter] array $cursor): string
    {
        return gp247_secret_encrypt(json_encode($cursor, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
