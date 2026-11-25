<?php

namespace GP247\Core\Commands;

use Carbon\CarbonImmutable;
use GP247\Core\Console\GP247Command;
use GP247\Core\PersonalData\Application\Erasure\ErasureJobRunner;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Retention\RetentionPolicies;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Domain\Retention\RetentionAction;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Core\PersonalData\Infrastructure\JobRepository;

/**
 * Cleans every retention item that is on and past its first allowed date, to the end — for sites
 * with cron or the ScheduledTasks plugin (without them the same work runs lazily while the site is
 * visited). --dry-run only counts what each item would handle now.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-lazy-run
 * @aidlc-adr personal-data-retention_sweep-runner
 */
class PersonalDataRetention extends GP247Command
{
    /** Most slices of one item in one run (a guard against a table that never empties). */
    private const MAX_SLICES = 10000;
    private const JOB_SECONDS = 30;

    /** @var string */
    protected $signature = 'gp247:personal-data-retention {--item= : Only this item (key)} {--dry-run : Count what would be cleaned, change nothing} {--json}';

    /** @var string */
    protected $description = 'Clean personal data past its retention period (carts, reset codes, messages, logs, old orders, unused accounts)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        if (!RetentionSweeper::ready()) {
            return $this->respondFailure('not_ready', 'Personal data tables are missing: run php artisan gp247:update first.');
        }
        $only = trim((string) $this->option('item'));
        $items = $only === '' ? PersonalDataRegistry::retentionItems() : array_filter([PersonalDataRegistry::retentionItem($only)]);
        if ($items === []) {
            return $this->respondFailure('unknown_item', 'Unknown retention item: ' . $only);
        }
        $dryRun = (bool) $this->option('dry-run');
        $sweeper = app(RetentionSweeper::class);
        $now = CarbonImmutable::now();
        $states = $sweeper->states($now);
        $results = [];
        foreach ($items as $item) {
            $state = $states[$item->key] ?? null;
            $result = ['item' => $item->key, 'status' => 'done', 'rows' => 0];
            if (!app(RetentionPolicies::class)->enabled($item)) {
                $result['status'] = 'off';
            } elseif ($state !== null && !RetentionSweeper::allowed($state, $now)) {
                $result = ['item' => $item->key, 'status' => 'not_yet', 'rows' => 0, 'first_allowed_at' => (string) $state->first_allowed_at];
            } elseif ($dryRun) {
                $result['status'] = 'dry_run';
                $result['rows'] = $sweeper->dueCount($item, $now);
            } else {
                $result = $this->runItem($sweeper, $item) + ['item' => $item->key];
            }
            $results[] = $result;
            if (!$this->isJson()) {
                $this->line(sprintf('  %s: %s, %d', $result['item'], $result['status'], $result['rows']));
            }
        }

        return $this->respondSuccess(['dry_run' => $dryRun, 'items' => $results]);
    }

    /**
     * @param RetentionSweeper $sweeper
     * @param RetentionItem    $item
     * @return array{status: string, rows: int}
     */
    private function runItem(RetentionSweeper $sweeper, RetentionItem $item): array
    {
        $rows = 0;
        for ($i = 0; $i < self::MAX_SLICES; $i++) {
            if ($item->action === RetentionAction::Anonymise) {
                // WHY: records are anonymised by a job; it must end before the next records are queued.
                $this->finishJobs();
            }
            $slice = $sweeper->slice($item, CarbonImmutable::now());
            if ($slice['error'] !== null) {
                return ['status' => 'failed', 'rows' => $rows];
            }
            $rows += $slice['rows'];
            if (!$slice['more']) {
                break;
            }
        }
        if ($item->action === RetentionAction::Anonymise) {
            $this->finishJobs();
        }

        return ['status' => 'done', 'rows' => $rows];
    }

    /**
     * @return void
     */
    private function finishJobs(): void
    {
        $jobs = app(JobRepository::class);
        $runner = app(ErasureJobRunner::class);
        for ($i = 0; $i < self::MAX_SLICES && ($id = $jobs->activeOfKind(JobRepository::KIND_RETENTION)) !== null; $i++) {
            if ($runner->runSlice($id, self::JOB_SECONDS) === JobRepository::FAILED) {
                return;
            }
        }
    }
}
