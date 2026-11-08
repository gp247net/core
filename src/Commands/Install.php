<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use GP247\Core\Support\Password\InitialAdminPassword;
use Illuminate\Support\Facades\Storage;

/**
 * First-time installation of the whole GP247 platform (migrate, seed, publish
 * assets, storage link, installed marker).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-005, US-CLI-003
 * @aidlc-adr system-cli_output-contract
 */
class Install extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:core-install {--force=0}
        {--admin-password= : Password of the administrator account (omit to get a random one shown once; a value typed here stays in the shell history)}';

    /**
     * Administrator password given with --admin-password; null when none was given.
     *
     * @var string|null
     */
    private ?string $adminPassword = null;

    /**
     * Administrator account of the last run in this process, for an orchestrating command
     * (gp247:install) to return in its own result: username + the generated password, or
     * null for a given / unchanged one.
     *
     * @var array{username: string, password: string|null, state: string}|null
     */
    public static ?array $lastAdmin = null;

    /**
     * Set by an orchestrating command (gp247:install) while it runs this step: the sign-in details are
     * then printed by the orchestrator after its last step, so the output of the later package installs
     * cannot scroll a password shown only once off the screen (US-CLI-003).
     *
     * @var bool
     */
    public static bool $deferWelcome = false;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'GP247 install';

    /**
     * Execute the console command.
     *
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        $force = $this->option('force') ?? 0;

        // WHY before any step: a password that does not meet the policy must stop the install
        // before anything is migrated; the message never repeats it (US-CLI-003).
        $given = $this->option('admin-password');
        if ($given !== null) {
            $problem = InitialAdminPassword::problem((string) $given);
            if ($problem !== null) {
                return $this->respondFailure('invalid_admin_password', $problem);
            }
            $this->adminPassword = (string) $given;
        }

        if (!$force) {
            if (!$this->checkGP247Installed()) {
                return $this->respondFailure(
                    'already_installed',
                    'GP247 has been installed. Delete '
                        . Storage::disk('local')->path('gp247-installed.txt')
                        . ' to reinstall, or pass --force=1.'
                );
            }
            // WHY: JSON / non-interactive callers cannot answer a prompt, so we
            // fail clearly instead of blocking; --force=1 is the unattended path.
            if ($this->isJson() || !$this->input->isInteractive()) {
                return $this->respondFailure(
                    'confirmation_required',
                    'Refusing to install without confirmation. Pass --force=1 for unattended install.'
                );
            }
            if (!$this->confirm('Are you sure you want to install GP247?')) {
                $this->info('Installation canceled');
                return $this->respondSuccess(['installed' => false, 'msg' => 'Installation canceled']);
            }
        }

        return $this->install();
    }

    /**
     * Announce the administrator account once the install has settled it: the full banner when this
     * command runs on its own, a pointer to the end of the output when an orchestrator prints it later.
     *
     * @param array{username: string, password: string|null, state: string} $admin
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-003
     */
    protected function announceAdmin(array $admin): void
    {
        if (self::$deferWelcome) {
            $this->info('---------------> Administrator account ready — the sign-in details are shown at the end of the install.');

            return;
        }

        foreach (self::welcomeLines($admin, $this->isJson()) as $line) {
            $this->line($line);
        }
    }

    /**
     * Lines of the welcome banner: logo, version, admin path and the administrator's sign-in details.
     *
     * @param array{username: string, password: string|null, state: string} $admin
     * @param bool $json Whether the caller prints a JSON envelope (the banner then goes to STDERR).
     * @return array<int, string>
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-003
     */
    public static function welcomeLines(array $admin, bool $json): array
    {
        $text = "
          _____  _____     ___  _  _   _____
         / ____|  __ \   |__ \| || | |___  |
        | |  __| |__) |     ) | || |_   / /
        | | |_ |  ___/     / /|__   _| / /
        | |__| | |        / /_   | |  / /
         \_____|_|       |____|  |_| /_/
        ";

        $text .= "\n             Welcome to GP247 ".config('gp247.core');
        $text .= "\n             Admin path: yourdomain/".config('gp247-config.env.GP247_ADMIN_PREFIX')."";
        $text .= "\n             Username: ".$admin['username'];
        $text .= "\n             Password: ".self::passwordLine($admin, $json);
        $text .= "\n";

        return explode("\n", $text);
    }

    /**
     * Password line of the banner. In --json mode the banner goes to STDERR, so a generated
     * password is only referred to there — the envelope carries it once (US-CLI-003).
     *
     * @param array{username: string, password: string|null, state: string} $admin
     * @param bool $json Whether the caller prints a JSON envelope.
     * @return string
     */
    private static function passwordLine(array $admin, bool $json): string
    {
        if ($admin['state'] === 'generated') {
            return $json
                ? 'generated — returned once in the JSON result (data.admin.password)'
                : $admin['password'].'   (shown only once — save it now, then change it in the admin)';
        }

        return $admin['state'] === 'given'
            ? 'the one you passed with --admin-password'
            : 'unchanged — the account already had its own password';
    }

    /**
     * Give the seeded administrator its password: the one passed, or a generated one.
     * Called as the last step so a failed install never leaves a password nobody saw.
     *
     * @return array{username: string, password: string|null, state: string}
     */
    private function settleAdminPassword(): array
    {
        $password = $this->adminPassword ?? InitialAdminPassword::generate();
        if (!InitialAdminPassword::assign($password)) {
            $this->addWarning('The administrator account already had its own password; it was left unchanged.');

            return ['username' => InitialAdminPassword::username(), 'password' => null, 'state' => 'kept'];
        }

        return $this->adminPassword === null
            ? ['username' => InitialAdminPassword::username(), 'password' => $password, 'state' => 'generated']
            : ['username' => InitialAdminPassword::username(), 'password' => null, 'state' => 'given'];
    }

    /**
     * Verify .env exists and an app key is present (generating one if needed).
     *
     * @return bool True when the environment is ready.
     */
    private function checkEnv(): bool
    {
        if (!file_exists(base_path() . "/.env")) {
            return false;
        } else if (!config('app.key')) {
            $this->runArtisan('key:generate');
        }
        return true;
    }

    /**
     * Whether GP247 is not yet installed (the installed marker is absent).
     *
     * @return bool True when it is safe to install.
     */
    private function checkGP247Installed(): bool
    {
        return !Storage::disk('local')->exists('gp247-installed.txt');
    }

    /**
     * Run the full installation sequence.
     *
     * @return int Exit code.
     */
    private function install(): int
    {
        if (!$this->checkEnv()) {
            return $this->respondFailure('env_missing', 'File .env not found');
        }

        $this->runArtisan('migrate');
        $this->info('---------------> Migrate default done!');

        \DB::connection(GP247_DB_CONNECTION)->table('migrations')->where('migration', '00_00_00_step1_create_tables_admin')->delete();
        $this->runArtisan('migrate', ['--path' => '/vendor/gp247/core/src/Database/Migrations/00_00_00_step1_create_tables_admin.php']);
        $this->info('---------------> Migrate schema GP247 done!');

        $this->runArtisan('db:seed', ['--class' => '\GP247\Core\Database\Seeders\DataDefaultSeeder', '--force' => true]);
        $this->info('---------------> Seeding database GP247 default done!');
        $this->runArtisan('db:seed', ['--class' => '\GP247\Core\Database\Seeders\DataStoreSeeder', '--force' => true]);
        $this->info('---------------> Seeding database GP247 system done!');
        $this->runArtisan('db:seed', ['--class' => '\GP247\Core\Database\Seeders\DataLocaleSeeder', '--force' => true]);
        $this->info('---------------> Seeding database GP247 local done!');

        $this->runArtisan('vendor:publish', ['--tag' => 'gp247:core-public']);
        $this->runArtisan('vendor:publish', ['--tag' => 'gp247:functions-except']);
        $this->runArtisan('vendor:publish', ['--tag' => 'lfm_public', '--force' => true]);
        $this->info('---------------> Publish laravel-filemanager assets done!');

        $this->runArtisan('storage:link');

        // WHY last: the seeded administrator never keeps the publicly known default password,
        // and a step failing earlier leaves no password nobody has seen (US-CLI-003).
        $admin = $this->settleAdminPassword();
        self::$lastAdmin = $admin;

        Storage::disk('local')->put('gp247-installed.txt', date('Y-m-d H:i:s'));

        $this->announceAdmin($admin);

        return $this->respondSuccess([
            'installed' => true,
            'core'      => config('gp247.core'),
            // A generated password is returned once here; a given one is never echoed.
            'admin'     => ['username' => $admin['username'], 'password' => $admin['password']],
        ]);
    }
}
