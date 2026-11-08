<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use GP247\Core\Models\AdminUser;
use GP247\Core\Support\Password\InitialAdminPassword;
use GP247\Core\Support\Password\PasswordPolicy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reset the password of an administrator account from the command line — the way back in when the
 * password shown once at install was lost and "Forgot password" cannot reach the account. Whoever can
 * run it already controls the server (.env, database); it replaces editing the admin_user table by hand
 * with a path that follows the password policy, the shared hash and signs old sessions out.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-admin-password-reset
 * @aidlc-adr system-cli_output-contract
 */
class AdminPassword extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:admin-password
        {username : Username of the administrator account}
        {--password= : The new password (omit to get a random one shown once; a value typed here stays in the shell history)}
        {--force=0 : Skip the confirmation (needed in scripts and for JSON output)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the password of an administrator account';

    /**
     * Execute the console command.
     *
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        $username = (string) $this->argument('username');
        $user = AdminUser::where('username', $username)->first();
        if ($user === null) {
            return $this->respondFailure('user_not_found', 'No administrator account has the username "' . $username . '".');
        }

        // WHY the current policy, not the install defaults: the owner may have tightened it on the
        // Password policy screen, and every path that sets a password must follow it.
        $policy = PasswordPolicy::for('admin');
        $given = $this->option('password');
        if ($given !== null && !InitialAdminPassword::meets($policy, (string) $given)) {
            // WHY no echo: the message may end up in a terminal log or CI output.
            return $this->respondFailure('invalid_password', 'The password does not meet the administrator password policy ('
                . $policy->min() . ' to ' . $policy->max() . ' characters, plus the rules set on the Password policy screen).');
        }

        if (!$this->option('force')) {
            // WHY: JSON / non-interactive callers cannot answer a prompt; --force=1 is the unattended path.
            if ($this->isJson() || !$this->input->isInteractive()) {
                return $this->respondFailure('confirmation_required', 'Refusing to reset the password without confirmation. Pass --force=1.');
            }
            if (!$this->confirmed($username)) {
                $this->info('Password reset canceled');

                return $this->respondSuccess(['reset' => false]);
            }
        }

        $generated = $given === null;
        $plain = $generated ? InitialAdminPassword::generateFor($policy) : (string) $given;
        $this->store($user, $plain);

        if ((int) $user->status === 0) {
            $this->addWarning('The account "' . $username . '" is locked — unlock it on the Users screen before signing in.');
        }
        Log::notice('Administrator password reset from the command line', ['username' => $username]);

        $this->printSignIn($username, $generated ? $plain : null);

        return $this->respondSuccess([
            'username'         => $username,
            // A generated password is returned once here; a chosen one is never echoed.
            'password'         => $generated ? $plain : null,
            'sessions_revoked' => true,
        ]);
    }

    /**
     * Ask the person at the terminal before resetting, saying what else it does.
     *
     * @param string $username
     * @return bool True to go on.
     */
    private function confirmed(string $username): bool
    {
        $this->warn('This signs "' . $username . '" out of every API client, MCP token and remembered browser.');

        return $this->confirm('Reset the password of ' . $username . '?', false);
    }

    /**
     * Save the new password through the model, so the hook that revokes every API session and token
     * of the account runs (US-CMP-api-admin-password-revokes-sessions), and sign remembered browsers out.
     *
     * @param AdminUser $user
     * @param string $plain
     * @return void
     */
    private function store(AdminUser $user, string $plain): void
    {
        $user->password = gp247_password_hash($plain);
        $user->remember_token = Str::random(60);
        $user->save();
    }

    /**
     * Print the sign-in details as the last block. In --json mode the block goes to STDERR, so a
     * generated password is only referred to there — the envelope carries it once.
     *
     * @param string $username
     * @param string|null $generated The generated password, or null when the owner chose it.
     * @return void
     */
    private function printSignIn(string $username, ?string $generated): void
    {
        $password = match (true) {
            $generated === null => 'the one you passed with --password',
            $this->isJson()     => 'generated — returned once in the JSON result (data.password)',
            default             => $generated . '   (shown only once — save it now)',
        };

        $this->line('');
        $this->info('Password reset. Sign in with:');
        $this->line('    Admin path: yourdomain/' . config('gp247-config.env.GP247_ADMIN_PREFIX'));
        $this->line('    Username: ' . $username);
        $this->line('    Password: ' . $password);
        $this->line('');
    }
}
