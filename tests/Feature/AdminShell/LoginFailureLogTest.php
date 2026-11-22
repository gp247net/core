<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Models\AdminUser;
use GP247\Core\Support\LoginFailure\LoginFailureRecorder;
use GP247\Core\Support\LoginThrottle;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use GP247\Core\Tests\Feature\AdminShell\Support\SeedsLoginFailures;
use GP247\Core\Tests\TestCase;

/**
 * Failed sign-ins of the recorded account groups (administrators by default) are kept,
 * without the password, for 30 days; recording never breaks a sign-in.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-log
 * @aidlc-adr admin-shell_login-failure-log
 */
class LoginFailureLogTest extends TestCase
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
        Cache::forget(LoginFailureRecorder::PRUNE_KEY);
    }

    /**
     * A persisted, active admin with a known password.
     *
     * @param string $username
     * @param string $password
     * @return AdminUser
     */
    private function makeAdmin(string $username, string $password): AdminUser
    {
        return AdminUser::create([
            'id' => 'AU-' . strtoupper($username),
            'username' => $username,
            'password' => gp247_password_hash($password),
            'name' => 'Tester',
            'email' => $username . '@test.local',
            'status' => 1,
        ]);
    }

    /**
     * Make $path the current request (channel detection reads it).
     *
     * @param string $path
     * @return Request
     */
    private function currentRequest(string $path): Request
    {
        $request = Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'phpunit-agent']);
        $this->app->instance('request', $request);

        return $request;
    }

    public function test_a_wrong_admin_password_on_the_sign_in_form_is_recorded_without_the_password(): void
    {
        $admin = $this->makeAdmin('lfweb' . random_int(1000, 9999), 'Right-Pass-2026');

        $this->post(route('admin.post_login'), ['username' => $admin->username, 'password' => 'Wrong-Secret-77']);

        $row = $this->failureTable()->first();
        $this->assertNotNull($row, 'the failed sign-in must be recorded');
        $this->assertSame('admin', $row->audience);
        $this->assertSame('web', $row->channel);
        $this->assertSame($admin->username, $row->identity);
        $this->assertSame((string) $admin->id, (string) $row->account_id);
        $this->assertSame('invalid_credentials', $row->reason);
        $this->assertNotEmpty($row->ip);
        $this->assertStringNotContainsString('Wrong-Secret-77', json_encode((array) $row));
    }

    public function test_a_correct_sign_in_is_not_recorded(): void
    {
        $admin = $this->makeAdmin('lfok' . random_int(1000, 9999), 'Right-Pass-2026');

        $this->post(route('admin.post_login'), ['username' => $admin->username, 'password' => 'Right-Pass-2026']);

        $this->assertSame(0, $this->failureTable()->count());
    }

    public function test_a_failure_through_the_api_is_recorded_on_the_api_channel(): void
    {
        $this->currentRequest('/api/v2/admin/login');

        event(new Failed('admin', null, ['username' => 'ghost-admin', 'password' => 'Api-Secret-1']));

        $row = $this->failureTable()->first();
        $this->assertSame('api', $row->channel);
        $this->assertSame('ghost-admin', $row->identity);
        $this->assertNull($row->account_id);
        $this->assertSame('203.0.113.9', $row->ip);
        $this->assertSame('phpunit-agent', $row->user_agent);
    }

    public function test_customer_failures_are_not_recorded_by_default_and_are_once_the_group_is_turned_on(): void
    {
        $this->currentRequest('/customer/login');
        event(new Failed('customer', null, ['email' => 'c@example.com', 'password' => 'x']));
        $this->assertSame(0, $this->failureTable()->count(), 'only administrators are recorded by default');

        $this->recordAudiences('admin,customer');
        event(new Failed('customer', null, ['email' => 'c@example.com', 'password' => 'x']));
        $this->assertSame(1, $this->failureTable()->where('audience', 'customer')->count());
    }

    public function test_a_burst_of_blocked_attempts_is_one_row_per_block_window(): void
    {
        $request = $this->currentRequest('/' . (defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin') . '/auth/login');
        $subject = 'name:lfburst' . random_int(1000, 9999);

        for ($i = 0; $i < 12; $i++) {
            LoginThrottle::hit($request, 'admin', $subject);
        }

        $this->assertSame(1, $this->failureTable()->where('reason', 'throttled')->count());
    }

    public function test_rows_older_than_the_keep_period_are_removed_on_the_next_record(): void
    {
        $this->seedFailures(2, ['created_at' => now()->subDays(31)]);
        $this->seedFailures(1, ['created_at' => now()->subDays(5)]);
        $this->currentRequest('/api/v2/admin/login');

        event(new Failed('admin', null, ['username' => 'late', 'password' => 'x']));

        $this->assertSame(0, $this->failureTable()->where('created_at', '<', now()->subDays(30))->count());
        $this->assertSame(2, $this->failureTable()->count());
    }

    public function test_an_invalid_utf8_user_agent_or_name_cannot_hide_the_attempt(): void
    {
        $request = Request::create('/api/v2/admin/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_USER_AGENT' => "bad\xFF\xFEagent\x01"]);
        $this->app->instance('request', $request);

        event(new Failed('admin', null, ['username' => "na\xFFme", 'password' => 'x']));

        $row = $this->failureTable()->first();
        $this->assertNotNull($row, 'a strict database must not refuse the row');
        $this->assertTrue(mb_check_encoding((string) $row->user_agent, 'UTF-8'));
        $this->assertStringNotContainsString("\x01", (string) $row->user_agent);
        $this->assertTrue(mb_check_encoding((string) $row->identity, 'UTF-8'));
    }

    public function test_one_group_adds_at_most_the_site_wide_cap_per_minute(): void
    {
        $this->currentRequest('/api/v2/admin/login');

        for ($i = 0; $i < LoginFailureRecorder::SITE_CAP_PER_MINUTE + 5; $i++) {
            event(new Failed('admin', null, ['username' => 'flood' . $i, 'password' => 'x']));
        }

        $this->assertLessThanOrEqual(LoginFailureRecorder::SITE_CAP_PER_MINUTE, $this->failureTable()->count());
    }

    public function test_a_recording_error_never_breaks_the_sign_in(): void
    {
        $this->app->bind(LoginFailureRecorder::class, fn () => new class extends LoginFailureRecorder {
            /** @return string */
            protected function table(): string
            {
                return 'no_such_table_for_login_failures';
            }
        });
        $admin = $this->makeAdmin('lfsafe' . random_int(1000, 9999), 'Right-Pass-2026');

        $wrong = $this->post(route('admin.post_login'), ['username' => $admin->username, 'password' => 'Wrong-Secret-77']);
        $wrong->assertRedirect();
        $this->assertGuest('admin');

        $this->post(route('admin.post_login'), ['username' => $admin->username, 'password' => 'Right-Pass-2026']);
        $this->assertAuthenticated('admin');
    }
}
