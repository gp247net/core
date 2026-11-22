<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\AdminShell\Support\AdminAlertRegistry;
use GP247\Core\Support\Health\LoginFailuresCheck;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Core\Tests\Feature\AdminShell\Support\SeedsLoginFailures;
use GP247\Core\Tests\TestCase;

/**
 * System alerts (and gp247:doctor) report signs of password guessing in the last
 * 24 hours.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-alert
 * @aidlc-adr admin-shell_login-failure-log, admin-shell_system-alert-registry
 */
class LoginFailureAlertTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsLoginFailures;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyFailureLog();
        $this->recordAudiences('admin');
    }

    /**
     * @return AdminAlert
     */
    private function evaluate(): AdminAlert
    {
        return (new LoginFailuresCheck)->evaluate();
    }

    public function test_the_check_is_registered_with_the_system_alerts(): void
    {
        $this->assertArrayHasKey('login_failures', AdminAlertRegistry::all());
    }

    public function test_a_few_failures_pass(): void
    {
        $this->seedFailures(9);

        $this->assertSame(AdminAlert::OK, $this->evaluate()->level);
    }

    public function test_ten_administrator_failures_in_a_day_need_review(): void
    {
        $this->seedFailures(10);

        $this->assertSame(AdminAlert::WARNING, $this->evaluate()->level);
    }

    public function test_a_blocked_administrator_sign_in_needs_review(): void
    {
        $this->seedFailures(1, ['reason' => 'throttled']);

        $this->assertSame(AdminAlert::WARNING, $this->evaluate()->level);
    }

    public function test_fifty_administrator_failures_in_a_day_are_critical(): void
    {
        $this->seedFailures(50);

        $this->assertSame(AdminAlert::CRITICAL, $this->evaluate()->level);
    }

    public function test_five_addresses_aiming_at_a_real_administrator_account_are_critical(): void
    {
        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4', '198.51.100.5'] as $ip) {
            $this->seedFailures(1, ['ip' => $ip, 'account_id' => 'AU-REAL', 'identity' => 'owner']);
        }

        $alert = $this->evaluate();
        $this->assertSame(AdminAlert::CRITICAL, $alert->level);
        $this->assertStringContainsString('198.51.100.', $alert->detail);
        $this->assertStringContainsString('owner', $alert->detail);
    }

    public function test_failures_older_than_a_day_are_not_counted(): void
    {
        $this->seedFailures(60, ['created_at' => now()->subHours(25)]);

        $this->assertSame(AdminAlert::OK, $this->evaluate()->level);
    }

    public function test_many_customer_failures_are_information_when_customers_are_recorded(): void
    {
        $this->recordAudiences('admin,customer');
        $this->seedFailures(100, ['audience' => 'customer', 'identity' => 'c@example.com']);

        $this->assertSame(AdminAlert::INFO, $this->evaluate()->level);
    }

    public function test_a_log_that_could_not_be_written_is_never_reported_as_all_clear(): void
    {
        \Illuminate\Support\Facades\Cache::put(\GP247\Core\Support\LoginFailure\LoginFailureRecorder::WRITE_ERROR_KEY, now()->getTimestamp(), 3600);

        $this->assertSame(AdminAlert::WARNING, $this->evaluate()->level);
    }

    public function test_the_detail_lists_only_administrator_names(): void
    {
        $this->recordAudiences('admin,customer');
        $this->seedFailures(10, ['identity' => 'admin-target']);
        $this->seedFailures(5, ['audience' => 'customer', 'identity' => 'shopper@example.com']);

        $detail = $this->evaluate()->detail;
        $this->assertStringContainsString('admin-target', $detail);
        $this->assertStringNotContainsString('shopper@example.com', $detail);
    }

    public function test_not_recording_administrators_needs_review(): void
    {
        $this->recordAudiences('customer');

        $this->assertSame(AdminAlert::WARNING, $this->evaluate()->level);
    }
}
