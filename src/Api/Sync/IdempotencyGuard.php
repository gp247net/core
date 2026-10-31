<?php

namespace GP247\Core\Api\Sync;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stored results of admin API writes keyed by `Idempotency-Key`: the first request claims
 * the key (a `pending` row guarded by a unique index, so two concurrent requests cannot
 * both run), its result is kept for a day and answered again to a retry with the same
 * body. Keys are scoped to the calling account.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-write-idempotency
 * @aidlc-adr api-sync_admin-idempotency-store
 */
final class IdempotencyGuard
{
    public const HEADER = 'Idempotency-Key';

    /** Claim answered by a new run. */
    public const RUN = 'run';
    /** Claim answered by the stored result. */
    public const REPLAY = 'replay';
    /** Same key, another body. */
    public const REUSED = 'reused';
    /** Same key still being processed by another request. */
    public const IN_PROGRESS = 'in_progress';

    /** Hours a stored result is kept. */
    private const KEEP_HOURS = 24;
    /** Minutes after which a `pending` claim is considered abandoned (the request died). */
    private const PENDING_MINUTES = 5;
    /** Largest response body stored for replay. */
    private const MAX_BODY_BYTES = 65536;
    /** Expired rows deleted per claim (lazy housekeeping, no cron). */
    private const PRUNE_BATCH = 100;

    /** @var bool|null Memo of the schema probe (one per process). */
    private static ?bool $ready = null;

    /**
     * Whether the key table exists (false until gp247:update ran on an upgraded site).
     *
     * @return bool
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            self::$ready = \Illuminate\Support\Facades\Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'api_idempotency');
        }
        return self::$ready;
    }

    /**
     * Same rule as the checkout key (8–255 printable characters).
     *
     * @param mixed $key
     * @return bool
     */
    public static function isWellFormed($key): bool
    {
        return is_string($key) && preg_match('/^[\x21-\x7E]{8,255}$/', $key) === 1;
    }

    /**
     * Scope of the keys of an account (two accounts may send the same key).
     *
     * @param Model|null $user
     * @param Request|null $request Fallback to the IP when no account is known.
     * @return string
     */
    public static function scopeFor(?Model $user, ?Request $request = null): string
    {
        if ($user !== null) {
            return strtolower(class_basename($user)) . ':' . $user->getKey();
        }

        return 'ip:' . ($request?->ip() ?? 'unknown');
    }

    /**
     * Fingerprint of a request: method, path and the body with keys sorted at every level.
     *
     * @param string $method
     * @param string $path
     * @param array  $input
     * @return string sha256 hex.
     */
    public static function fingerprint(string $method, string $path, array $input): string
    {
        return hash('sha256', strtoupper($method) . "\n" . trim($path, '/') . "\n" . json_encode(self::sorted($input)));
    }

    /**
     * Claim $key for this request.
     *
     * @param string $scope
     * @param string $key
     * @param string $fingerprint
     * @return array{0: string, 1: object|null} [decision, stored row (REPLAY) or claimed row id holder].
     */
    public function claim(string $scope, string $key, string $fingerprint): array
    {
        $this->prune();
        $keyHash = hash('sha256', $key);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $id = $this->table()->insertGetId([
                    'scope' => $scope, 'key_hash' => $keyHash, 'request_hash' => $fingerprint,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return [self::RUN, (object) ['id' => $id]];
            } catch (QueryException $e) {
                $row = $this->table()->where('scope', $scope)->where('key_hash', $keyHash)->first();
                if ($row === null) {
                    // WHY: lost a race with a delete of an abandoned claim — try once more.
                    continue;
                }
                if ($row->status === 'pending') {
                    if (strtotime((string) $row->created_at) > now()->subMinutes(self::PENDING_MINUTES)->getTimestamp()) {
                        return [self::IN_PROGRESS, null];
                    }
                    $this->table()->where('id', $row->id)->where('status', 'pending')->delete();
                    continue;
                }
                if (!hash_equals((string) $row->request_hash, $fingerprint)) {
                    return [self::REUSED, null];
                }

                return [self::REPLAY, $row];
            }
        }

        return [self::IN_PROGRESS, null];
    }

    /**
     * Keep the result of the claimed run, or release the key when it should be retried.
     *
     * @param int      $id
     * @param Response $response
     * @return void
     */
    public function complete(int $id, Response $response): void
    {
        $body = (string) $response->getContent();
        if ($response->getStatusCode() >= 500 || strlen($body) > self::MAX_BODY_BYTES) {
            $this->release($id);
            return;
        }
        $this->table()->where('id', $id)->update([
            'status' => 'done', 'response_status' => $response->getStatusCode(), 'response_body' => $body, 'updated_at' => now(),
        ]);
    }

    /**
     * Free the key of a run that failed (a retry runs again).
     *
     * @param int $id
     * @return void
     */
    public function release(int $id): void
    {
        $this->table()->where('id', $id)->delete();
    }

    /**
     * Delete a small batch of expired keys (lazy housekeeping on writes).
     *
     * @return void
     */
    private function prune(): void
    {
        $ids = $this->table()->where('created_at', '<', now()->subHours(self::KEEP_HOURS))
            ->orderBy('id')->limit(self::PRUNE_BATCH)->pluck('id')->all();
        if ($ids) {
            $this->table()->whereIn('id', $ids)->delete();
        }
    }

    /**
     * @param mixed $value
     * @return mixed $value with array keys sorted at every level.
     */
    private static function sorted($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map([self::class, 'sorted'], $value);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function table()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_idempotency');
    }
}
