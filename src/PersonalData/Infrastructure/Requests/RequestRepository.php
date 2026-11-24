<?php

namespace GP247\Core\PersonalData\Infrastructure\Requests;

use GP247\Core\PersonalData\Domain\Requests\RequestStatus;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and writes personal data requests. Status changes go through RequestWorkflow, never
 * through update() from a screen.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-screen, US-personal-data-requests-admin-create
 * @aidlc-adr personal-data-requests_request-model
 */
final class RequestRepository
{
    /**
     * @return ConnectionInterface
     */
    public function db(): ConnectionInterface
    {
        return DB::connection(GP247_DB_CONNECTION);
    }

    /**
     * @return Builder
     */
    public function table(): Builder
    {
        return $this->db()->table(PersonalDataSchema::table(PersonalDataSchema::REQUEST));
    }

    /**
     * @param array<string, mixed> $values
     * @return string Request id.
     */
    public function create(array $values): string
    {
        $id = (string) Str::ulid();
        $this->table()->insert(['id' => $id, 'updated_at' => now()] + $values);

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
     * @param string               $id
     * @param array<string, mixed> $changes
     * @return void
     */
    public function update(string $id, array $changes): void
    {
        $this->table()->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    /**
     * @param string               $id
     * @param string               $status  The status the request must still have.
     * @param array<string, mixed> $changes
     * @return int Rows changed (0 when the request moved meanwhile).
     */
    public function updateIfStatus(string $id, string $status, array $changes): int
    {
        return $this->table()->where('id', $id)->where('status', $status)->update($changes + ['updated_at' => now()]);
    }

    /**
     * @param string $erasureId
     * @return object|null The request this erasure was run for.
     */
    public function findByErasure(string $erasureId): ?object
    {
        return $this->table()->where('erasure_id', $erasureId)->orderByDesc('received_at')->first();
    }

    /**
     * @param string $customerId
     * @param string $kind
     * @return object|null The customer's request of this kind that is still open, if any.
     */
    public function openFor(string $customerId, string $kind): ?object
    {
        return $this->table()->where('customer_id', $customerId)->where('kind', $kind)
            ->whereIn('status', RequestStatus::openValues())->orderBy('received_at')->first();
    }

    /**
     * @param \DateTimeInterface $now
     * @return string|null A waiting request whose wait is over (oldest first).
     */
    public function nextDue(\DateTimeInterface $now): ?string
    {
        $id = $this->table()->where('status', RequestStatus::Waiting->value)->where('wait_until', '<=', $now)
            ->orderBy('wait_until')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @param \DateTimeInterface $now
     * @return string|null A request on hold whose next check is due.
     */
    public function nextOnHold(\DateTimeInterface $now): ?string
    {
        $id = $this->table()->where('status', RequestStatus::OnHold->value)
            ->where(fn (Builder $q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->orderBy('next_check_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * @return list<object> Requests being processed (to notice an erasure that ended or never started).
     */
    public function processing(): array
    {
        return $this->table()->where('status', RequestStatus::Processing->value)->orderBy('executed_at')->limit(50)->get()->all();
    }

    /**
     * @param \DateTimeInterface $now
     * @return int Open requests past their due date.
     */
    public function countOverdue(\DateTimeInterface $now): int
    {
        return $this->table()->whereIn('status', RequestStatus::openValues())->where('due_at', '<', $now)->count();
    }

    /**
     * @return int Requests whose erasure failed.
     */
    public function countFailed(): int
    {
        return $this->table()->where('status', RequestStatus::Failed->value)->count();
    }

    /**
     * @param \DateTimeInterface $before
     * @param int                $limit
     * @return int Closed requests removed (closed before $before).
     */
    public function pruneClosedBefore(\DateTimeInterface $before, int $limit = 500): int
    {
        $ids = $this->table()->whereNotNull('closed_at')->where('closed_at', '<', $before)->limit($limit)->pluck('id')->all();

        return $ids === [] ? 0 : $this->table()->whereIn('id', $ids)->delete();
    }
}
