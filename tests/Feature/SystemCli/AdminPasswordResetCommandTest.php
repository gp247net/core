<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use GP247\Core\Models\AdminUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Core\Tests\Feature\AdminShell\Support\SetsPasswordPolicy;
use GP247\Core\Tests\TestCase;

/**
 * A site owner with access to the server resets the password of an administrator account from the
 * command line — the way back in when the password shown once at install was lost and "Forgot password"
 * cannot reach the account.
 *
 * WHY a dedicated account: every test creates its own administrator inside the transaction, so the real
 * accounts of the site are never touched and everything is rolled back.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-admin-password-reset
 */
class AdminPasswordResetCommandTest extends TestCase
{
    use DatabaseTransactions;
    use SetsPasswordPolicy;

    private const USERNAME = 'pwreset-owner';
    private const OLD_PASSWORD = 'Old-Owner-Pass-2026';

    /**
     * Create the administrator the tests reset.
     *
     * @param int $status 1 active, 0 locked.
     * @return AdminUser
     */
    private function owner(int $status = 1): AdminUser
    {
        return AdminUser::create([
            'id'             => 'AU-PWRESET-OWNER',
            'username'       => self::USERNAME,
            'password'       => Hash::make(self::OLD_PASSWORD),
            'name'           => 'Owner',
            'email'          => 'pwreset-owner@test.local',
            'status'         => $status,
            'remember_token' => 'remembered-browser-token',
        ]);
    }

    /**
     * Run the command and return exit code + everything it printed.
     *
     * @param array<string, mixed> $args
     * @return array{code: int, output: string}
     */
    private function reset(array $args): array
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call('gp247:admin-password', $args, $buffer);

        return ['code' => $code, 'output' => $buffer->fetch()];
    }

    /**
     * Pull the generated password out of the "Password: <value>   (shown only once …)" line.
     *
     * @param string $output
     * @return string
     */
    private function printedPassword(string $output): string
    {
        $this->assertSame(1, preg_match('/Password: (\S+)\s+\(shown only once/', $output, $m), 'the generated password is printed');

        return $m[1];
    }

    public function test_the_owner_sets_a_password_they_choose_and_can_sign_in_with_it(): void
    {
        $this->owner();

        $run = $this->reset(['username' => self::USERNAME, '--password' => 'Chosen-Owner-Pass-2026', '--force' => 1]);

        $this->assertSame(0, $run['code']);
        $hash = AdminUser::where('username', self::USERNAME)->value('password');
        $this->assertTrue(Hash::check('Chosen-Owner-Pass-2026', $hash));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $hash));
        $this->assertStringNotContainsString('Chosen-Owner-Pass-2026', $run['output'], 'a chosen password is never printed back');
    }

    public function test_a_generated_password_meets_the_current_admin_policy_and_is_printed_once(): void
    {
        $this->owner();
        $this->setPolicyRange('admin', 16, 40);

        $run = $this->reset(['username' => self::USERNAME, '--force' => 1]);

        $this->assertSame(0, $run['code']);
        $password = $this->printedPassword($run['output']);
        $this->assertGreaterThanOrEqual(16, strlen($password));
        $this->assertLessThanOrEqual(40, strlen($password));
        $this->assertSame(1, substr_count($run['output'], $password), 'printed exactly once');
        $this->assertTrue(Hash::check($password, AdminUser::where('username', self::USERNAME)->value('password')));
        $this->assertGreaterThan(strrpos($run['output'], 'Username:'), strrpos($run['output'], $password), 'the password is in the last block');
    }

    public function test_a_generated_password_still_meets_a_policy_that_requires_symbols(): void
    {
        $this->owner();
        $this->setPolicyRange('admin', 12, 64);
        foreach (['letter', 'mixedcase', 'number', 'symbol'] as $switch) {
            $this->setPolicy('admin', $switch, '1');
        }

        $run = $this->reset(['username' => self::USERNAME, '--force' => 1, '--json' => true]);
        $envelope = $this->envelope($run['output']);

        $this->assertTrue($envelope['ok']);
        $rule = \GP247\Core\Support\Password\PasswordPolicy::for('admin')->rule();
        $this->assertTrue(\Illuminate\Support\Facades\Validator::make(['p' => $envelope['data']['password']], ['p' => [$rule]])->passes());
        $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $envelope['data']['password'], 'contains a symbol');
    }

    public function test_a_password_below_the_admin_policy_is_refused_without_being_repeated_and_nothing_changes(): void
    {
        $this->owner();
        $this->setPolicyRange('admin', 14, 64);

        $run = $this->reset(['username' => self::USERNAME, '--password' => 'Short-Pass-1', '--force' => 1, '--json' => true]);
        $envelope = $this->envelope($run['output']);

        $this->assertNotSame(0, $run['code']);
        $this->assertSame('invalid_password', $envelope['error']['code']);
        $this->assertStringNotContainsString('Short-Pass-1', $run['output']);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, AdminUser::where('username', self::USERNAME)->value('password')));
    }

    public function test_an_unknown_username_is_refused_and_nothing_changes(): void
    {
        $this->owner();

        $run = $this->reset(['username' => 'nobody-' . self::USERNAME, '--force' => 1, '--json' => true]);

        $this->assertNotSame(0, $run['code']);
        $this->assertSame('user_not_found', $this->envelope($run['output'])['error']['code']);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, AdminUser::where('username', self::USERNAME)->value('password')));
    }

    public function test_without_confirmation_in_a_script_the_command_refuses_and_changes_nothing(): void
    {
        $this->owner();

        $run = $this->reset(['username' => self::USERNAME, '--json' => true]);

        $this->assertNotSame(0, $run['code']);
        $this->assertSame('confirmation_required', $this->envelope($run['output'])['error']['code']);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, AdminUser::where('username', self::USERNAME)->value('password')));
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->owner();

        $this->artisan('gp247:admin-password', ['username' => self::USERNAME])
            ->expectsConfirmation('Reset the password of ' . self::USERNAME . '?', 'no')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, AdminUser::where('username', self::USERNAME)->value('password')));
    }

    public function test_resetting_signs_the_account_out_of_every_api_client_and_remembered_browser(): void
    {
        $owner = $this->owner();
        $owner->createToken('phone');
        $owner->createToken('mcp-agent');
        $this->assertSame(2, $owner->tokens()->count());

        $this->reset(['username' => self::USERNAME, '--force' => 1]);

        $this->assertSame(0, $owner->tokens()->count());
        $this->assertNotSame('remembered-browser-token', AdminUser::where('username', self::USERNAME)->value('remember_token'));
    }

    public function test_the_json_envelope_returns_a_generated_password_once_and_never_a_chosen_one(): void
    {
        $this->owner();

        $generated = $this->envelope($this->reset(['username' => self::USERNAME, '--force' => 1, '--json' => true])['output']);
        $this->assertTrue($generated['ok']);
        $this->assertSame(self::USERNAME, $generated['data']['username']);
        $this->assertIsString($generated['data']['password']);
        $this->assertTrue($generated['data']['sessions_revoked']);

        $chosenRun = $this->reset(['username' => self::USERNAME, '--password' => 'Chosen-Owner-Pass-2026', '--force' => 1, '--json' => true]);
        $chosen = $this->envelope($chosenRun['output']);
        $this->assertNull($chosen['data']['password']);
        $this->assertStringNotContainsString('Chosen-Owner-Pass-2026', $chosenRun['output']);
    }

    public function test_a_locked_account_gets_the_password_with_a_warning_and_stays_locked(): void
    {
        $this->owner(status: 0);

        $envelope = $this->envelope($this->reset(['username' => self::USERNAME, '--force' => 1, '--json' => true])['output']);

        $this->assertTrue($envelope['ok']);
        $this->assertNotEmpty(array_filter($envelope['warnings'], fn ($w) => str_contains($w, 'locked')));
        $this->assertSame(0, (int) AdminUser::where('username', self::USERNAME)->value('status'));
    }

    public function test_the_reset_is_logged_without_the_password(): void
    {
        $this->owner();
        Log::spy();

        $run = $this->reset(['username' => self::USERNAME, '--force' => 1]);
        $password = $this->printedPassword($run['output']);

        Log::shouldHaveReceived('notice')->once()->withArgs(function ($message, $context = []) use ($password) {
            return ($context['username'] ?? null) === self::USERNAME
                && !str_contains(json_encode([$message, $context]), $password);
        });
    }

    /**
     * The JSON envelope: the last non-empty line of the output (human lines go before it).
     *
     * @param string $output
     * @return array<string, mixed>
     */
    private function envelope(string $output): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded, 'the envelope is the last line');

        return $decoded;
    }
}
