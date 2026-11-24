<?php

namespace GP247\Core\PersonalData\Infrastructure\Export;

use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data packages of customers: where each one stands, and its download link. The link itself is
 * never stored — only its SHA-256 and who it was given to; using it marks the package downloaded
 * in one conditional UPDATE, so two requests racing on the same link cannot both download.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package, US-customer-privacy-self-export
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ExportRepository
{
    public const BUILDING = 'building';
    public const READY = 'ready';
    public const DOWNLOADED = 'downloaded';
    public const EXPIRED = 'expired';
    public const FAILED = 'failed';
    public const AUDIENCE_CUSTOMER = 'customer';
    public const AUDIENCE_ADMIN = 'admin';
    /** A download that breaks halfway must not cost the customer the package: a few tries, then gone. */
    public const MAX_DOWNLOADS = 3;

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
        return $this->db()->table(PersonalDataSchema::table(PersonalDataSchema::EXPORT));
    }

    /**
     * @param string|null $requestId
     * @param string|null $customerId
     * @param string      $audience   Who the package is for: customer (web, app) or admin.
     * @return string Package id.
     */
    public function create(?string $requestId, ?string $customerId, string $audience = self::AUDIENCE_CUSTOMER): string
    {
        $id = (string) Str::ulid();
        $this->table()->insert([
            'id' => $id,
            'request_id' => $requestId,
            'customer_id' => $customerId,
            'audience' => $audience === self::AUDIENCE_ADMIN ? self::AUDIENCE_ADMIN : self::AUDIENCE_CUSTOMER,
            'status' => self::BUILDING,
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
     * @param string               $id
     * @param array<string, mixed> $changes
     * @return void
     */
    public function update(string $id, array $changes): void
    {
        $this->table()->where('id', $id)->update($changes + ['updated_at' => now()]);
    }

    /**
     * @param string             $id
     * @param int                $size
     * @param \DateTimeInterface $expires
     * @return bool Whether the package was still being built (and is now ready).
     */
    public function markReady(string $id, int $size, \DateTimeInterface $expires): bool
    {
        return $this->table()->where('id', $id)->where('status', self::BUILDING)
            ->update(['status' => self::READY, 'file_size' => $size, 'ready_at' => now(), 'expires_at' => $expires, 'updated_at' => now()]) === 1;
    }

    /**
     * @param string $requestId
     * @return object|null The package made for a request.
     */
    public function forRequest(string $requestId): ?object
    {
        return $this->table()->where('request_id', $requestId)->orderByDesc('created_at')->first();
    }

    /**
     * @param string             $customerId
     * @param \DateTimeInterface $since
     * @param string             $audience   customer or admin: each has its own package.
     * @return object|null The customer's latest package for this audience made after $since that did not fail.
     */
    public function latestFor(string $customerId, \DateTimeInterface $since, string $audience = self::AUDIENCE_CUSTOMER): ?object
    {
        return $this->table()->where('customer_id', $customerId)->where('audience', $audience)->where('created_at', '>=', $since)
            ->where('status', '!=', self::FAILED)->orderByDesc('created_at')->first();
    }

    /**
     * @param string $id
     * @param string $tokenHash
     * @param string $owner     Who the link was given to (customer:<id>, admin:<id>).
     * @return void
     */
    public function setToken(string $id, string $tokenHash, string $owner): void
    {
        $this->table()->where('id', $id)->where('status', self::READY)
            ->update(['token_hash' => $tokenHash, 'token_owner' => $owner, 'updated_at' => now()]);
    }

    /**
     * Use a download link once. The package stays downloadable (with a new link) until it was
     * downloaded MAX_DOWNLOADS times or its 24 hours are over.
     *
     * @param string $id
     * @param string $tokenHash
     * @param string $owner
     * @return bool Whether this call may send the file.
     */
    public function claim(string $id, string $tokenHash, string $owner): bool
    {
        $now = now();

        return $this->table()->where('id', $id)->where('status', self::READY)->where('downloads', '<', self::MAX_DOWNLOADS)
            ->where('token_hash', $tokenHash)->where('token_owner', $owner)->where('expires_at', '>', $now)
            ->update([
                // WHY first: MySQL evaluates SET left to right with already-updated values.
                'status' => DB::raw("(CASE WHEN downloads + 1 >= " . self::MAX_DOWNLOADS . " THEN '" . self::DOWNLOADED . "' ELSE status END)"),
                'downloads' => DB::raw('downloads + 1'),
                'downloaded_at' => $now,
                'token_hash' => null,
                'updated_at' => $now,
            ]) === 1;
    }

    /**
     * @param \DateTimeInterface $now
     * @param int                $limit
     * @return list<object> Packages whose files must go (downloaded, past their 24 hours, or failed)
     *         and are not cleaned yet.
     */
    public function toClean(\DateTimeInterface $now, int $limit = 50): array
    {
        return $this->table()->where(function (Builder $q) use ($now) {
            $q->where(fn (Builder $r) => $r->where('status', self::READY)->where('expires_at', '<=', $now))
                ->orWhere('status', self::DOWNLOADED)
                ->orWhere('status', self::FAILED);
        })->whereNull('cleaned_at')->limit($limit)->get()->all();
    }

    /**
     * @param string $id
     * @param string $status Final status (expired for a package nobody downloaded in time).
     * @return void
     */
    public function markCleaned(string $id, string $status): void
    {
        $this->table()->where('id', $id)->update(['status' => $status, 'cleaned_at' => now(), 'token_hash' => null, 'updated_at' => now()]);
    }

    /**
     * @param string $customerId
     * @return list<object> The customer's packages whose file exists or may still be made.
     */
    public function openFor(string $customerId): array
    {
        return $this->table()->where('customer_id', $customerId)->whereIn('status', [self::BUILDING, self::READY])->get()->all();
    }

    /**
     * @param int $limit
     * @return list<object> Packages still being built (to notice a job that gave up).
     */
    public function building(int $limit = 50): array
    {
        return $this->table()->where('status', self::BUILDING)->orderBy('created_at')->limit($limit)->get()->all();
    }
}
