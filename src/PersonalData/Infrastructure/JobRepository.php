<?php

namespace GP247\Core\PersonalData\Infrastructure;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Erasure jobs and their lease. A slice owns a job only after one atomic UPDATE took a free or
 * expired lease (same scheme as backup jobs); a lease that was never released counts as one
 * failed attempt. The cursor is encrypted: it may hold the values being erased.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class JobRepository
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const ACTIVE = [self::QUEUED, self::RUNNING];

    /**
     * @param string               $kind     erase | reapply
     * @param string               $origin
     * @param string|null          $customerId
     * @param string|null          $actorGuard
     * @param string|null          $actorId
     * @param string|null          $erasureId
     * @param array<string, mixed> $cursor
     * @return string Job id.
     */
    public function create(string $kind, string $origin, ?string $customerId, ?string $actorGuard, ?string $actorId, ?string $erasureId, array $cursor): string
    {
        $id = (string) Str::ulid();
        $this->table()->insert([
            'id' => $id,
            'kind' => $kind,
            'status' => self::QUEUED,
            'subject_customer_id' => $customerId,
            'origin' => $origin,
            'actor_guard' => $actorGuard,
            'actor_id' => $actorId,
            'erasure_id' => $erasureId,
            'cursor' => $this->encode($cursor),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
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
     * @param string            $id
     * @param string            $owner Random id of this slice.
     * @param DateTimeInterface $until Lease end.
     * @return bool True when this slice now owns the job.
     */
    public function acquire(string $id, string $owner, DateTimeInterface $until): bool
    {
        $now = now();

        return $this->table()
            ->where('id', $id)
            ->whereIn('status', self::ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->update([
                // WHY first: MySQL evaluates SET left to right with already-updated values.
                'attempts' => DB::raw('attempts + (CASE WHEN lease_owner IS NULL THEN 0 ELSE 1 END)'),
                'lease_owner' => $owner,
                'lease_until' => $until,
                'status' => self::RUNNING,
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * @param string               $id
     * @param string               $owner
     * @param array<string, mixed> $cursor
     * @return void
     */
    public function save(string $id, string $owner, array $cursor): void
    {
        $this->table()->where('id', $id)->where('lease_owner', $owner)->update(['cursor' => $this->encode($cursor), 'updated_at' => now()]);
    }

    /**
     * @param string                    $id
     * @param string                    $owner
     * @param string                    $status
     * @param array<string, mixed>|null $cursor Null clears it (job finished: nothing of the subject stays).
     * @param string|null               $error
     * @param bool                      $failedAttempt
     * @return void
     */
    public function release(string $id, string $owner, string $status, ?array $cursor, ?string $error = null, bool $failedAttempt = false): void
    {
        $values = [
            'status' => $status,
            'cursor' => $cursor === null ? null : $this->encode($cursor),
            'lease_owner' => null,
            'lease_until' => null,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'updated_at' => now(),
        ];
        if ($failedAttempt) {
            $values['attempts'] = DB::raw('attempts + 1');
        }
        $this->table()->where('id', $id)->where('lease_owner', $owner)->update($values);
    }

    /**
     * @param string $kind
     * @param string $customerId
     * @return string|null Id of an unfinished job of this kind for this customer.
     */
    public function activeFor(string $kind, string $customerId): ?string
    {
        $id = $this->table()->where('kind', $kind)->where('subject_customer_id', $customerId)
            ->whereIn('status', self::ACTIVE)->orderBy('created_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @return string|null Id of an active job whose lease is free or expired.
     */
    public function nextRunnable(): ?string
    {
        $now = now();
        $id = $this->table()
            ->whereIn('status', self::ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $now))
            ->orderBy('created_at')
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @param object $job
     * @return array<string, mixed>
     * @throws RuntimeException When the cursor cannot be read (encryption key changed).
     */
    public function cursor(object $job): array
    {
        if ($job->cursor === null || $job->cursor === '') {
            return [];
        }
        $plain = gp247_secret_decrypt((string) $job->cursor, ['table' => PersonalDataSchema::JOB, 'id' => (string) $job->id]);
        try {
            $data = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('The progress of this erasure job cannot be read.');
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $cursor
     * @return string
     */
    private function encode(array $cursor): string
    {
        return gp247_secret_encrypt(json_encode($cursor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return Builder
     */
    private function table(): Builder
    {
        return DB::connection(GP247_DB_CONNECTION)->table(PersonalDataSchema::table(PersonalDataSchema::JOB));
    }
}
