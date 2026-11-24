<?php

namespace GP247\Core\PersonalData\Infrastructure\Requests;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\PersonalData\Application\ReapplyService;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Requests\UndeclaredSources;
use GP247\Core\Support\Health\HealthText;

/**
 * "System alerts" and `gp247:doctor` check of personal data: an erasure that failed (critical),
 * requests past their due date, tables of customer data nobody declared (warnings). Throws while
 * the tables do not exist yet, so the registry skips it instead of showing it as passed.
 *
 * @aidlc-unit personal-data-requests
 * @aidlc-story US-personal-data-requests-overdue-alert, US-personal-data-registry-undeclared-detection
 * @aidlc-adr personal-data-requests_undeclared-sources
 */
final class PersonalDataCheck implements AdminAlertCheck
{
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
        $overdue = $requests->countOverdue(now());
        if ($overdue > 0) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.personal_data_overdue', ['count' => $overdue]), $overdue . ' request(s) past their due date', ...$fix);
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

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.personal_data_ok'));
    }
}
