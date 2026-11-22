<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Core\Tests\TestCase;

/**
 * E2E for the modern TailAdmin auth screens (US-AUI-007): guest-accessible
 * login / forgot / reset pages served in-place at the legacy entry points
 * (admin.login/forgot/password_reset) that post into the existing legacy auth
 * endpoints (presentation-only, backend unchanged). Also verifies the modern
 * login form wires correctly into the legacy guard via a real credential
 * round-trip.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-007
 * @aidlc-adr ADR-001, ADR-002
 */
class AuthScreensTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Create a persisted, active admin with a known password.
     *
     * @param string $username Login username.
     * @param string $password Plain password (stored bcrypt'd).
     * @return AdminUser
     */
    private function makeAdmin(string $username = 'tester', string $password = 'secret-pass'): AdminUser
    {
        return AdminUser::create([
            'id' => 'AU-' . strtoupper($username),
            'username' => $username,
            'password' => bcrypt($password),
            'name' => 'Tester',
            'email' => $username . '@test.local',
            'status' => 1,
        ]);
    }

    public function test_login_route_is_registered(): void
    {
        $this->assertTrue(app('router')->has('admin.login'));
    }

    public function test_guest_sees_login_form_posting_to_legacy_endpoint(): void
    {
        $response = $this->get(route('admin.login'));

        $response->assertOk();
        $response->assertSee(route('admin.post_login'), false);
        $response->assertSee('name="username"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="_token"', false);
    }

    public function test_authenticated_admin_is_redirected_away_from_login(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.login'))
            ->assertRedirect();
    }

    public function test_guest_sees_forgot_form(): void
    {
        $response = $this->get(route('admin.forgot'));

        $response->assertOk();
        $response->assertSee(route('admin.post_forgot'), false);
        $response->assertSee('name="email"', false);
    }

    public function test_guest_sees_reset_form_with_token(): void
    {
        $response = $this->get(route('admin.password_reset', ['token' => 'tok-123']));

        $response->assertOk();
        $response->assertSee(route('admin.password_request'), false);
        $response->assertSee('tok-123', false);
        $response->assertSee('name="password_confirmation"', false);
    }

    public function test_valid_credentials_authenticate_via_legacy_endpoint(): void
    {
        $this->makeAdmin('alice', 'good-pass');

        $response = $this->post(route('admin.post_login'), [
            'username' => 'alice',
            'password' => 'good-pass',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticated('admin');
    }

    public function test_resetting_the_password_by_email_checks_the_administrator_password_policy(): void
    {
        // the reset used Laravel's default rule (8+), ignoring the policy.
        $table = \Illuminate\Support\Facades\DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $table->clone()->where('store_id', (string) GP247_STORE_ID_GLOBAL)->where('key', 'admin_password_min')->update(['value' => '14']);
        $table->clone()->where('store_id', (string) GP247_STORE_ID_GLOBAL)->where('key', 'admin_password_max')->update(['value' => '64']);
        $controller = app(\GP247\Core\Controllers\Auth\ResetPasswordController::class);
        $rules = (new \ReflectionMethod($controller, 'rules'))->invoke($controller);
        $data = ['token' => 'tok', 'email' => 'alice@test.local'];

        $short = \Illuminate\Support\Facades\Validator::make($data + ['password' => 'thirteen-char', 'password_confirmation' => 'thirteen-char'], $rules);
        $this->assertTrue($short->fails());
        $this->assertTrue($short->errors()->has('password'));
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make($data + ['password' => 'fourteen-chars', 'password_confirmation' => 'fourteen-chars'], $rules)->passes());
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->makeAdmin('bob', 'good-pass');

        $response = $this->post(route('admin.post_login'), [
            'username' => 'bob',
            'password' => 'wrong-pass',
        ]);

        $response->assertRedirect();
        $this->assertGuest('admin');
    }
}
