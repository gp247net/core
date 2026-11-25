<?php

namespace GP247\Core\PersonalData\Application\Retention;

use Carbon\CarbonImmutable;
use GP247\Core\PersonalData\Application\Erasure\ErasureSteps;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SourceQuery;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\Retention\RetentionAction;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Core\PersonalData\Domain\RowRule;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\Retention\RetentionStateRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Cleans data past its retention period without cron, a small slice at a time:
 *
 *  - the lazy tick runs at most one slice of one item that is on, past its first allowed clean
 *    and due (each item at most once an hour, at once again while rows remain);
 *  - row items delete at most ROW_LIMIT rows by the rule of their declared source;
 *  - record items hand at most RECORD_LIMIT records to one erasure-engine job (anonymised there,
 *    with the sources under them), one job at a time;
 *  - account items create, through the customer's own erasure flow, requests for accounts unused
 *    for the period (told 30 days before).
 *
 * An error is recorded on the item (exception class only) and never reaches the request that
 * carried the tick.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-lazy-run, US-personal-data-retention-admin-log, US-personal-data-retention-order-anonymise
 * @aidlc-adr personal-data-retention_sweep-runner, personal-data-retention_record-anonymise
 */
final class RetentionSweeper
{
    public const ROW_LIMIT = 200;
    public const RECORD_LIMIT = 50;
    public const EVERY_MINUTES = 60;
    private const RECORDS_LOCK = 'gp247:personal-data:retention-records';

    /**
     * @param RetentionPolicies        $policies
     * @param RetentionStateRepository $state
     * @param JobRepository            $jobs
     * @param InactiveAccounts         $accounts
     */
    public function __construct(
        private readonly RetentionPolicies $policies,
        private readonly RetentionStateRepository $state,
        private readonly JobRepository $jobs,
        private readonly InactiveAccounts $accounts,
    ) {
    }

    /**
     * @return bool Whether the tables it needs exist.
     */
    public static function ready(): bool
    {
        return RetentionStateRepository::ready() && ErasureService::ready();
    }

    /**
     * One slice of the item that waited longest among those due.
     *
     * @param CarbonImmutable|null $now
     * @return string|null Key of the item run.
     */
    public function tick(?CarbonImmutable $now = null): ?string
    {
        if (!self::ready()) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $states = $this->states($now);
        $due = [];
        foreach (PersonalDataRegistry::retentionItems() as $item) {
            $row = $states[$item->key] ?? null;
            if ($row === null || !$this->policies->enabled($item) || !self::allowed($row, $now)) {
                continue;
            }
            if ($row->next_run_at !== null && CarbonImmutable::parse((string) $row->next_run_at)->greaterThan($now)) {
                continue;
            }
            $due[] = [$row->next_run_at === null ? '' : (string) $row->next_run_at, $item->sortOrder(), $item->key, $item];
        }
        if ($due === []) {
            return null;
        }
        // WHY this order: the item that waited longest first, then in screen order.
        usort($due, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        $this->slice($due[0][3], $now);

        return $due[0][2];
    }

    /**
     * "Run now" on the screen: one slice, whatever the hour; before the first allowed clean only
     * when the owner confirmed cleaning early.
     *
     * @param RetentionItem        $item
     * @param bool                 $early
     * @param CarbonImmutable|null $now
     * @return array{rows: int, more: bool, error: string|null, blocked: string|null}
     *         blocked: off (the item is off) or not_yet (before its first allowed clean).
     */
    public function runNow(RetentionItem $item, bool $early = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        if (!$this->policies->enabled($item)) {
            return ['rows' => 0, 'more' => false, 'error' => null, 'blocked' => 'off'];
        }
        $row = $this->states($now)[$item->key] ?? null;
        if ($row !== null && !self::allowed($row, $now)) {
            if (!$early) {
                return ['rows' => 0, 'more' => false, 'error' => null, 'blocked' => 'not_yet'];
            }
            $this->state->allowFrom($item->key, $now);
        }
        if ($item->action === RetentionAction::Anonymise) {
            // WHY: the owner asks to run again after fixing what made the last job fail — it continues where it stopped.
            $latest = $this->jobs->latestOfKind(JobRepository::KIND_RETENTION);
            if ($latest !== null && (string) $latest->status === JobRepository::FAILED) {
                $this->jobs->retry((string) $latest->id);
            }
        }

        return $this->slice($item, $now) + ['blocked' => null];
    }

    /**
     * @param RetentionItem   $item
     * @param CarbonImmutable $now
     * @return array{rows: int, more: bool, error: string|null}
     */
    public function slice(RetentionItem $item, CarbonImmutable $now): array
    {
        try {
            [$rows, $more, $cursor] = match ($item->action) {
                RetentionAction::Delete => $this->deleteRows($item, $now),
                RetentionAction::Anonymise => $this->queueRecords($item, $now),
                RetentionAction::EraseAccounts => $this->accounts->sweep($item, $this->policies->days($item), $this->enabledSince($item), $this->cursorOf($item), $now),
            };
        } catch (\Throwable $e) {
            $this->state->recordError($item->key, $e::class, $now, $now->addMinutes(self::EVERY_MINUTES));
            if (function_exists('gp247_report')) {
                gp247_report('Personal data retention ' . $item->key . ' failed: ' . $e::class);
            }

            return ['rows' => 0, 'more' => false, 'error' => $e::class];
        }
        $this->state->recordRun($item->key, $rows, $now, $more ? $now : $now->addMinutes(self::EVERY_MINUTES), $cursor);

        return ['rows' => $rows, 'more' => $more, 'error' => null];
    }

    /**
     * @param RetentionItem        $item
     * @param CarbonImmutable|null $now
     * @param int|null             $days Days to count with (a value not saved yet); null = the saved policy.
     * @return int Rows, records or accounts the item would handle now (preview, dry run, alert).
     */
    public function dueCount(RetentionItem $item, ?CarbonImmutable $now = null, ?int $days = null): int
    {
        $now ??= CarbonImmutable::now();
        $days = $days === null ? $this->policies->days($item) : $item->clampDays($days);
        if ($item->action === RetentionAction::EraseAccounts) {
            return $this->accounts->count($item, $days, $now);
        }
        $query = $this->dueQuery($item, $now, $days);

        return $query === null ? 0 : $query->count();
    }

    /**
     * @param CarbonImmutable $now
     * @return array<string, object> Item key => state, after recording items seen for the first time.
     */
    public function states(CarbonImmutable $now): array
    {
        $seen = [];
        foreach (PersonalDataRegistry::retentionItems() as $item) {
            $seen[$item->key] = $this->policies->enabled($item);
        }
        $this->state->ensure($seen, $now);

        return $this->state->all();
    }

    /**
     * @param object          $row
     * @param CarbonImmutable $now
     * @return bool Whether the first allowed clean has come.
     */
    public static function allowed(object $row, CarbonImmutable $now): bool
    {
        return $row->first_allowed_at === null || !CarbonImmutable::parse((string) $row->first_allowed_at)->greaterThan($now);
    }

    /**
     * @param RetentionItem   $item
     * @param CarbonImmutable $now
     * @return array{0: int, 1: bool, 2: null}
     */
    private function deleteRows(RetentionItem $item, CarbonImmutable $now): array
    {
        $query = $this->dueQuery($item, $now);
        if ($query === null) {
            return [0, false, null];
        }
        // WHY by age and limited: a slice stays small, and tables without a single key (carts) can be cleaned too.
        $deleted = $query->orderBy($item->ageColumn())->limit(self::ROW_LIMIT)->delete();

        return [$deleted, $deleted >= self::ROW_LIMIT, null];
    }

    /**
     * @param RetentionItem   $item
     * @param CarbonImmutable $now
     * @return array{0: int, 1: bool, 2: null}
     */
    private function queueRecords(RetentionItem $item, CarbonImmutable $now): array
    {
        // WHY a lock: the lazy tick, "run now" and the command may queue at the same moment.
        $lock = Cache::lock(self::RECORDS_LOCK, 30);
        if (!$lock->get()) {
            return [0, true, null];
        }
        try {
            // WHY one job at a time: records of a running job are not anonymised yet and would be picked again.
            if ($this->jobs->activeOfKind(JobRepository::KIND_RETENTION) !== null) {
                return [0, true, null];
            }
            // WHY stop on a failed job: queuing the same records again would fail again, every hour, for ever.
            if ((string) ($this->jobs->latestOfKind(JobRepository::KIND_RETENTION)->status ?? '') === JobRepository::FAILED) {
                throw new \RuntimeException('The last anonymisation job failed');
            }
            $source = self::sourceOf($item);
            $query = $this->dueQuery($item, $now);
            if ($query === null) {
                return [0, false, null];
            }
            $ids = $query->orderBy($source->key)->limit(self::RECORD_LIMIT)->pluck($source->key)->map(fn ($id) => (string) $id)->all();
            if ($ids === []) {
                return [0, false, null];
            }
            $this->jobs->create(JobRepository::KIND_RETENTION, 'retention', null, 'system', null, null, ErasureSteps::startRecords(DataSubject::records($source->table, $ids)));

            return [count($ids), count($ids) >= self::RECORD_LIMIT, null];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param RetentionItem   $item
     * @param CarbonImmutable $now
     * @param int|null        $days Null = the saved policy.
     * @return Builder|null Rows past the period, or null when the table is not on this site.
     * @throws \LogicException When the item names a table it may not clean.
     */
    private function dueQuery(RetentionItem $item, CarbonImmutable $now, ?int $days = null): ?Builder
    {
        $source = PersonalDataRegistry::source((string) $item->table);
        if ($item->action === RetentionAction::Anonymise) {
            $source = self::sourceOf($item);
        } elseif ($source !== null && $source->rowRule() !== RowRule::Delete) {
            throw new \LogicException('Retention item ' . $item->key . ' names a source whose rows are not deleted');
        } elseif ($source === null && !array_key_exists((string) $item->table, PersonalDataRegistry::notCustomerTables())) {
            // WHY: deleting rows of an undeclared table would bypass the declared rules of the engine.
            throw new \LogicException('Retention item ' . $item->key . ' names an undeclared table');
        }
        $physical = $source !== null ? $source->physicalTable() : GP247_DB_PREFIX . $item->table;
        if (!SourceQuery::db()->getSchemaBuilder()->hasTable($physical)) {
            return null;
        }
        $query = SourceQuery::db()->table($physical)->where($item->ageColumn(), '<', $item->cutoff($days ?? $this->policies->days($item), $now));
        if ($source !== null) {
            foreach ($source->equals() as $column => $value) {
                $query->where($column, $value);
            }
            if ($source->filter() !== null) {
                ($source->filter())($query);
            }
        }
        foreach ($item->equals() as $column => $value) {
            $query->where($column, $value);
        }
        if ($item->filter() !== null) {
            ($item->filter())($query);
        }

        return $query;
    }

    /**
     * @param RetentionItem $item
     * @return PersonalDataSource
     * @throws \LogicException When the records are not a declared source.
     */
    private static function sourceOf(RetentionItem $item): PersonalDataSource
    {
        $source = PersonalDataRegistry::source((string) $item->table);
        if ($source === null) {
            throw new \LogicException('Retention item ' . $item->key . ' names an undeclared source');
        }

        return $source;
    }

    /**
     * @param RetentionItem $item
     * @return CarbonImmutable|null Since when the item is on (null = off or unknown).
     */
    private function enabledSince(RetentionItem $item): ?CarbonImmutable
    {
        $at = $this->state->find($item->key)->enabled_at ?? null;

        return $at === null ? null : CarbonImmutable::parse((string) $at);
    }

    /**
     * @param RetentionItem $item
     * @return string|null
     */
    private function cursorOf(RetentionItem $item): ?string
    {
        $cursor = $this->state->find($item->key)->cursor ?? null;

        return $cursor === null ? null : (string) $cursor;
    }
}
