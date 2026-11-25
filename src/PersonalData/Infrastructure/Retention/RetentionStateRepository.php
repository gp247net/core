<?php

namespace GP247\Core\PersonalData\Infrastructure\Retention;

use Carbon\CarbonImmutable;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Where each retention item stands, one row per item: when it may clean for the first time
 * (30 days after the site first saw it, so a site that updates never loses old data at once),
 * since when it is on, when it last ran and runs next, rows handled, the last error, and a
 * cursor for items that walk a table. Holds no customer data.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-lazy-run, US-personal-data-retention-admin-log
 * @aidlc-adr personal-data-retention_sweep-runner
 */
final class RetentionStateRepository
{
    /** Days between the first time an item is seen and its first clean. */
    public const FIRST_CLEAN_DAYS = 30;
    private const ERROR_MAX = 255;

    /** @var bool|null */
    private static ?bool $ready = null;

    /**
     * @return bool Whether the state table exists (site updated).
     */
    public static function ready(): bool
    {
        return self::$ready ??= DB::connection(GP247_DB_CONNECTION)->getSchemaBuilder()->hasTable(PersonalDataSchema::table(PersonalDataSchema::RETENTION));
    }

    /**
     * @return void Forget the memo (tests, after gp247:update in the same process).
     */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    /**
     * Record the items seen for the first time: their first clean is FIRST_CLEAN_DAYS away.
     *
     * @param array<string, bool> $items Item key => whether it is on now (on ⇒ "on since" is now).
     * @param CarbonImmutable     $now
     * @return void
     */
    public function ensure(array $items, CarbonImmutable $now): void
    {
        if ($items === []) {
            return;
        }
        $known = $this->table()->whereIn('item_key', array_keys($items))->pluck('item_key')->map(fn ($k) => (string) $k)->all();
        $rows = [];
        foreach ($items as $key => $enabled) {
            if (in_array((string) $key, $known, true)) {
                continue;
            }
            $rows[] = [
                'item_key' => (string) $key,
                'first_allowed_at' => $now->addDays(self::FIRST_CLEAN_DAYS),
                'enabled_at' => $enabled ? $now : null,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            // WHY insertOrIgnore: two requests may see a new item at the same moment.
            $this->table()->insertOrIgnore($rows);
        }
    }

    /**
     * @return array<string, object> Item key => state row.
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->table()->get() as $row) {
            $out[(string) $row->item_key] = $row;
        }

        return $out;
    }

    /**
     * @param string $key
     * @return object|null
     */
    public function find(string $key): ?object
    {
        return $this->table()->where('item_key', $key)->first();
    }

    /**
     * @param string               $key
     * @param int                  $rows   Rows handled by this slice.
     * @param CarbonImmutable      $now
     * @param CarbonImmutable      $next   When the item is due again.
     * @param string|null          $cursor Where a walking item stops (null = start again next time).
     * @return void
     */
    public function recordRun(string $key, int $rows, CarbonImmutable $now, CarbonImmutable $next, ?string $cursor = null): void
    {
        $this->table()->where('item_key', $key)->increment('total_rows', max(0, $rows), [
            'last_run_at' => $now,
            'next_run_at' => $next,
            'last_rows' => $rows,
            'last_error' => null,
            'errors' => 0,
            'cursor' => $cursor,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param string          $key
     * @param string          $error Short text without customer data (exception class).
     * @param CarbonImmutable $now
     * @param CarbonImmutable $next
     * @return void
     */
    public function recordError(string $key, string $error, CarbonImmutable $now, CarbonImmutable $next): void
    {
        $this->table()->where('item_key', $key)->increment('errors', 1, [
            'last_run_at' => $now,
            'next_run_at' => $next,
            'last_rows' => 0,
            'last_error' => mb_substr($error, 0, self::ERROR_MAX),
            'updated_at' => $now,
        ]);
    }

    /**
     * Turned on: like a new item, nothing is cleaned for FIRST_CLEAN_DAYS (the system check warns
     * 7 days before), unless its first clean was already further away.
     *
     * @param string               $key
     * @param CarbonImmutable|null $since Null when turned off.
     * @return void
     */
    public function setEnabledAt(string $key, ?CarbonImmutable $since): void
    {
        $this->table()->where('item_key', $key)->update(['enabled_at' => $since, 'cursor' => null, 'updated_at' => now()]);
        if ($since !== null) {
            $first = $since->addDays(self::FIRST_CLEAN_DAYS);
            $this->table()->where('item_key', $key)
                ->where(fn (Builder $q) => $q->whereNull('first_allowed_at')->orWhere('first_allowed_at', '<', $first))
                ->update(['first_allowed_at' => $first]);
        }
    }

    /**
     * The owner confirmed cleaning before the first date (screen): allowed from now.
     *
     * @param string          $key
     * @param CarbonImmutable $now
     * @return void
     */
    public function allowFrom(string $key, CarbonImmutable $now): void
    {
        $this->table()->where('item_key', $key)->where('first_allowed_at', '>', $now)->update(['first_allowed_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @return Builder
     */
    private function table(): Builder
    {
        return DB::connection(GP247_DB_CONNECTION)->table(PersonalDataSchema::table(PersonalDataSchema::RETENTION));
    }
}
