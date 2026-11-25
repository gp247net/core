<?php

namespace GP247\Core\PersonalData\Infrastructure\Requests;

use Carbon\CarbonImmutable;
use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Backup\Application\BackupFeature;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Retention\RetentionPolicies;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\ReapplyService;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Requests\UndeclaredSources;
use GP247\Core\Support\Health\HealthText;

/**
 * "System alerts" and `gp247:doctor` check of personal data: an erasure that failed (critical),
 * a retention item that keeps failing, requests past their due date, tables of customer data
 * nobody declared, the first clean of a retention item within 7 days (with the rows it removes),
 * a retention item removing business data while backups are off, no ZIP support for data
 * packages (warnings). Throws while the tables do not exist yet, so the registry skips it instead
 * of showing it as passed.
 *
 * @aidlc-unit personal-data-requests, personal-data-retention
 * @aidlc-story US-personal-data-requests-overdue-alert, US-personal-data-registry-undeclared-detection, US-personal-data-retention-admin-log
 * @aidlc-adr personal-data-requests_undeclared-sources, personal-data-retention_sweep-runner
 */
final class PersonalDataCheck implements AdminAlertCheck
{
    /** Failed runs in a row before a retention item is reported. */
    private const RETENTION_ERRORS = 3;
    /** Days ahead the first clean of a retention item is announced. */
    private const FIRST_CLEAN_NOTICE_DAYS = 7;

    /** @return string */
    public function key(): string
    {
        return 'personal_data';
    }

    /** @return int */
    public function ttl(): int
    {
        return 600;
    }

    /**
     * @return AdminAlert
     * @throws \RuntimeException When the tables are missing (site not updated).
     */
    public function evaluate(): AdminAlert
    {
        if (!RequestScheduler::ready()) {
            throw new \RuntimeException('personal data tables missing — run php artisan gp247:update');
        }
        $fix = [HealthText::route('admin.personal_data_requests'), HealthText::t('admin.alerts.fix_personal_data')];
        $requests = app(RequestRepository::class);
        $failed = $requests->countFailed() + ReapplyService::failedCount();
        if ($failed > 0) {
            return AdminAlert::critical($this->key(), HealthText::t('admin.alerts.personal_data_failed', ['count' => $failed]), $failed . ' erasure(s) failed', ...$fix);
        }
        $retention = $this->retentionState();
        $retentionFix = [HealthText::route('admin.personal_data_retention'), HealthText::t('admin.alerts.fix_personal_data_retention')];
        if ($retention['failing'] > 0) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.personal_data_retention_failed', ['count' => $retention['failing']]), $retention['failing'] . ' retention item(s) failing', ...$retentionFix);
        }
        $overdue = $requests->countOverdue(now());
        if ($overdue > 0) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.personal_data_overdue', ['count' => $overdue]), $overdue . ' request(s) past their due date', ...$fix);
        }
        if ($retention['soon'] > 0) {
            return AdminAlert::warning(
                $this->key(),
                HealthText::t('admin.alerts.personal_data_retention_soon', ['days' => self::FIRST_CLEAN_NOTICE_DAYS, 'count' => $retention['soon']]),
                $retention['soon'] . ' row(s) cleaned by a first retention run within ' . self::FIRST_CLEAN_NOTICE_DAYS . ' days',
                ...$retentionFix
            );
        }
        if ($retention['risky'] && !BackupFeature::enabled()) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.personal_data_retention_no_backup'), 'a retention item removing business data is on, backups are off', ...$retentionFix);
        }
        $undeclared = app(UndeclaredSources::class)->find();
        if ($undeclared !== []) {
            $tables = array_column($undeclared, 'table');

            return AdminAlert::warning(
                $this->key(),
                HealthText::t('admin.alerts.personal_data_undeclared', ['count' => count($tables)]),
                'undeclared: ' . implode(', ', array_slice($tables, 0, 10)) . (count($tables) > 10 ? ' …' : ''),
                ...$fix
            );
        }

        if (!ExportService::zipAvailable()) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.personal_data_no_zip'), 'PHP zip extension missing: data packages cannot be built');
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.personal_data_ok'));
    }

    /**
     * @return array{failing: int, soon: int, risky: bool} Items failing in a row, rows of first cleans
     *         coming within FIRST_CLEAN_NOTICE_DAYS, whether an item removing business data is on.
     */
    private function retentionState(): array
    {
        $out = ['failing' => 0, 'soon' => 0, 'risky' => false];
        if (!RetentionSweeper::ready()) {
            return $out;
        }
        $now = CarbonImmutable::now();
        $sweeper = app(RetentionSweeper::class);
        $policies = app(RetentionPolicies::class);
        $states = $sweeper->states($now);
        foreach (PersonalDataRegistry::retentionItems() as $item) {
            $state = $states[$item->key] ?? null;
            if ($state === null || !$policies->enabled($item)) {
                continue;
            }
            $out['risky'] = $out['risky'] || $item->isRisky();
            if ((int) $state->errors >= self::RETENTION_ERRORS) {
                $out['failing']++;
            }
            $first = $state->first_allowed_at === null ? null : CarbonImmutable::parse((string) $state->first_allowed_at);
            if ($first !== null && $first->greaterThan($now) && !$first->greaterThan($now->addDays(self::FIRST_CLEAN_NOTICE_DAYS))) {
                try {
                    $out['soon'] += $sweeper->dueCount($item, $now);
                } catch (\Throwable $e) {
                    // WHY: an item that cannot even be counted is reported once it runs and fails.
                }
            }
        }

        return $out;
    }
}
