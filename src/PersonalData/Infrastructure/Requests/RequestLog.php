<?php

namespace GP247\Core\PersonalData\Infrastructure\Requests;

use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The history of every personal data request, kept as evidence: rows are only added — this
 * class has no way to change or delete one row — and leave only after the keep period (5 years).
 * It holds ids, events, who acted (an administrator's IP; never a customer's) and counts, never
 * the erased data.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-audit-log
 * @aidlc-adr personal-data-requests_request-log
 */
final class RequestLog
{
    public const KEEP_YEARS = 5;
    private const PRUNE_LOCK = 'gp247:personal-data:request-log-prune';
    private const PRUNE_CHUNK = 500;

    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_CUSTOMER = 'customer';
    public const ACTOR_SYSTEM = 'system';

    /**
     * @param string|null          $requestId Null for an action on no request (e.g. a table marked as not customer data).
     * @param string               $event
     * @param array{type: string, id?: string|null} $actor
     * @param string|null          $reason
     * @param array<string, mixed> $context Codes and counts only.
     * @return void
     */
    public function append(?string $requestId, string $event, array $actor, ?string $reason = null, array $context = []): void
    {
        $type = in_array($actor['type'] ?? '', [self::ACTOR_ADMIN, self::ACTOR_CUSTOMER, self::ACTOR_SYSTEM], true) ? $actor['type'] : self::ACTOR_SYSTEM;
        $this->table()->insert([
            'id' => (string) Str::ulid(),
            'request_id' => $requestId,
            'event' => mb_substr($event, 0, 40),
            'actor_type' => $type,
            'actor_id' => isset($actor['id']) ? mb_substr((string) $actor['id'], 0, 64) : null,
            // WHY admin only: a customer's address would outlive their erasure here.
            'ip' => $type === self::ACTOR_ADMIN ? mb_substr((string) (function_exists('gp247_get_real_ip_client') ? gp247_get_real_ip_client() : request()->ip()), 0, 45) : null,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'context' => $context === [] ? null : (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * @param string $requestId
     * @return list<object> Oldest first.
     */
    public function forRequest(string $requestId): array
    {
        return $this->table()->where('request_id', $requestId)->orderBy('created_at')->orderBy('id')->get()->all();
    }

    /**
     * @param \DateTimeInterface|null $from
     * @param \DateTimeInterface|null $to
     * @return \Generator<int, array<string, mixed>> Rows for a CSV export (evidence), oldest first.
     */
    public function export(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): \Generator
    {
        $query = $this->table()->orderBy('created_at')->orderBy('id');
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
        foreach ($query->cursor() as $row) {
            yield (array) $row;
        }
    }

    /**
     * Remove history older than the keep period, and closed requests as old, at most once an
     * hour and in chunks (no cron).
     *
     * @param RequestRepository $requests
     * @return int Rows removed in this call.
     */
    public function prune(RequestRepository $requests): int
    {
        if (!Cache::add(self::PRUNE_LOCK, 1, 3600)) {
            return 0;
        }
        $before = now()->subYears(self::KEEP_YEARS);
        $ids = $this->table()->where('created_at', '<', $before)->limit(self::PRUNE_CHUNK)->pluck('id')->all();
        $removed = $ids === [] ? 0 : $this->table()->whereIn('id', $ids)->delete();

        return $removed + $requests->pruneClosedBefore($before, self::PRUNE_CHUNK);
    }

    /**
     * @return Builder
     */
    private function table(): Builder
    {
        return DB::connection(GP247_DB_CONNECTION)->table(PersonalDataSchema::table(PersonalDataSchema::REQUEST_LOG));
    }
}
