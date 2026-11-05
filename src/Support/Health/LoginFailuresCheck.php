<?php

namespace GP247\Core\Support\Health;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\Support\LoginFailure\LoginFailureRecorder;
use GP247\Core\Support\LoginFailure\LoginFailureSchema;
use GP247\Core\Support\LoginFailure\LoginFailureSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Signs of password guessing in the last 24 hours, from the failed sign-in log.
 * Throws while the table does not exist yet (site not updated), so the registry
 * skips the check instead of showing it as passed.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-alert, US-admin-shell-system-alerts
 * @aidlc-adr admin-shell_login-failure-log, admin-shell_system-alert-registry
 */
class LoginFailuresCheck implements AdminAlertCheck
{
    public const ADMIN_WARN = 10;
    public const ADMIN_CRITICAL = 50;
    public const ADMIN_CRITICAL_IPS = 5;
    public const OTHER_INFO = 100;
    public const WINDOW_HOURS = 24;
    private const TOP = 3;

    /** @return string */
    public function key(): string
    {
        return 'login_failures';
    }

    /** @return int */
    public function ttl(): int
    {
        return 300;
    }

    /**
     * @return AdminAlert
     * @throws \RuntimeException When the log table does not exist yet.
     */
    public function evaluate(): AdminAlert
    {
        $table = GP247_DB_PREFIX . LoginFailureSchema::TABLE;
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable($table)) {
            throw new \RuntimeException('login failure table missing — run php artisan gp247:update');
        }
        $fix = [HealthText::route('admin.login_failures'), HealthText::t('admin.alerts.fix_login_failures'), HealthText::doc('security-built-in')];

        if (!LoginFailureSettings::enabled('admin')) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.login_failures_not_recorded'), 'failed administrator sign-ins are not recorded', ...$fix);
        }

        $since = now()->subHours(self::WINDOW_HOURS);
        $rows = fn () => DB::connection(GP247_DB_CONNECTION)->table($table)->where('created_at', '>=', $since);
        $adminFailed = $rows()->where('audience', 'admin')->where('reason', LoginFailureRecorder::REASON_INVALID)->count();
        $adminBlocked = $rows()->where('audience', 'admin')->where('reason', LoginFailureRecorder::REASON_THROTTLED)->count();
        $realAccountIps = $rows()->where('audience', 'admin')->whereNotNull('account_id')->distinct()->count('ip');
        $others = $rows()->where('audience', '<>', 'admin')->where('reason', LoginFailureRecorder::REASON_INVALID)->count();

        $detail = sprintf(
            'last %dh — admin: %d failed, %d blocked, %d address(es) on existing accounts; other groups: %d failed%s',
            self::WINDOW_HOURS, $adminFailed, $adminBlocked, $realAccountIps, $others, $this->top($rows)
        );

        if ($adminFailed >= self::ADMIN_CRITICAL || $realAccountIps >= self::ADMIN_CRITICAL_IPS) {
            $ips = $rows()->where('audience', 'admin')->distinct()->count('ip');

            return AdminAlert::critical($this->key(), HealthText::t('admin.alerts.login_failures_critical', ['count' => $adminFailed, 'ips' => $ips]), $detail, ...$fix);
        }
        if ($adminFailed >= self::ADMIN_WARN || $adminBlocked > 0) {
            return AdminAlert::warning($this->key(), HealthText::t('admin.alerts.login_failures_warning', ['count' => $adminFailed + $adminBlocked]), $detail, ...$fix);
        }
        // WHY before the "all clear": when rows cannot be written the table stays quiet,
        // and a quiet table must not read as "no attack" (fail closed).
        $writeError = Cache::get(LoginFailureRecorder::WRITE_ERROR_KEY);
        if ($writeError !== null) {
            return AdminAlert::warning(
                $this->key(),
                HealthText::t('admin.alerts.login_failures_write_error'),
                'a failed sign-in could not be recorded at ' . date('Y-m-d H:i:s', (int) $writeError) . ' (see the application log); ' . $detail,
                ...$fix
            );
        }
        if ($others >= self::OTHER_INFO) {
            return AdminAlert::info($this->key(), HealthText::t('admin.alerts.login_failures_info', ['count' => $others]), $detail, ...$fix);
        }

        return AdminAlert::ok($this->key(), HealthText::t('admin.alerts.login_failures_ok'), $detail);
    }

    /**
     * "; top addresses: … ; top names: …" over the window, or '' when nothing was recorded.
     *
     * @param \Closure(): \Illuminate\Database\Query\Builder $rows
     * @return string
     */
    private function top(\Closure $rows): string
    {
        $parts = [];
        foreach (['ip' => 'top addresses', 'identity' => 'top names'] as $column => $label) {
            // WHY admin only + quoted + short: this is an administrator alert, and the values
            // are attacker-typed text that must not read like part of the sentence.
            $top = $rows()->where('audience', 'admin')->whereNotNull($column)
                ->select($column, DB::raw('COUNT(*) as total'))
                ->groupBy($column)
                ->orderByDesc('total')
                ->limit(self::TOP)
                ->get()
                ->map(fn ($row) => '"' . Str::limit((string) $row->{$column}, 40) . '" (' . $row->total . ')')
                ->all();
            if ($top !== []) {
                $parts[] = $label . ': ' . implode(', ', $top);
            }
        }

        return $parts === [] ? '' : '; ' . implode('; ', $parts);
    }
}
