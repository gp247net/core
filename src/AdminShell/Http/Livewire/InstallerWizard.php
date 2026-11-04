<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\Database\Seeders\DataDefaultSeeder;
use GP247\Core\Models\AdminUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

/**
 * Multi-step web installer wizard for GP247 on shared hosting.
 *
 * Runs before gp247-installed.txt exists — must NOT call any GP247 helper
 * that requires DB access (gp247_store_info, gp247_config_admin, etc.).
 * Registered under the gp247-core Livewire namespace so it is reachable
 * on the /install route regardless of installation state.
 *
 * Steps:
 *   1 — Environment check (PHP version, extensions, directory permissions)
 *   2 — Database configuration (host, port, db, user, pass)
 *   3 — Site & admin account configuration
 *   4 — Running installation (migrate, seed, hand the seeded admin the entered
 *       credentials, write flag)
 *   5 — Done
 *
 * Every step action is a no-op once the install marker exists.
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-001
 * @aidlc-story US-DEP-002
 * @aidlc-adr installer-deploy_initial-admin-account
 */
class InstallerWizard extends Component
{
    /**
     * Lowest PHP the platform boots on, as a PHP_VERSION_ID. Mirrors `php` in
     * this package's composer.json — keep both in step.
     */
    public const MIN_PHP_VERSION_ID = 80300;

    /** Install marker on the `local` disk — the single "is it installed?" signal. */
    public const INSTALLED_MARKER = 'gp247-installed.txt';

    // -----------------------------------------------------------------------
    // Wizard state
    // -----------------------------------------------------------------------

    public int $step = 1;

    /** @var string[] Environment errors found in step 1. */
    public array $envErrors = [];

    // -----------------------------------------------------------------------
    // Step 2 — Database
    // -----------------------------------------------------------------------

    public string $dbHost = '127.0.0.1';
    public string $dbPort = '3306';
    public string $dbName = '';
    public string $dbUser = '';
    public string $dbPass = '';

    /** Error message from a failed DB connection test. */
    public string $dbError = '';

    // -----------------------------------------------------------------------
    // Step 3 — Site & admin account
    // -----------------------------------------------------------------------

    public string $siteName = 'GP247';
    public string $adminUsername = 'admin';
    public string $adminEmail = '';
    public string $adminPassword = '';
    public string $adminName = 'Administrator';

    // -----------------------------------------------------------------------
    // Step 4 — Installation progress
    // -----------------------------------------------------------------------

    public string $installError = '';

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    /**
     * Redirect away from installer if GP247 is already installed.
     */
    public function mount(): void
    {
        if ($this->isInstalled()) {
            $this->redirect(url(config('gp247.admin_prefix', 'gp247_admin') . '/auth/login'));
        }
    }

    /**
     * Whether GP247 is already installed (the marker exists on the local disk).
     *
     * @return bool
     */
    protected function isInstalled(): bool
    {
        return Storage::disk('local')->exists(self::INSTALLED_MARKER);
    }

    // -----------------------------------------------------------------------
    // Step 1 — Environment check
    // -----------------------------------------------------------------------

    /**
     * Check the server environment requirements for GP247.
     *
     * @return string[] List of human-readable error messages; empty if OK.
     */
    public function checkEnvironment(): array
    {
        $errors = [];

        if (PHP_VERSION_ID < self::MIN_PHP_VERSION_ID) {
            $errors[] = 'PHP >= 8.3 is required. Current version: ' . PHP_VERSION;
        }

        // Required extensions
        $required = ['pdo', 'pdo_mysql', 'mbstring', 'gd', 'fileinfo', 'xml', 'zip'];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "PHP extension '{$ext}' is required but not loaded.";
            }
        }

        // Writable directories
        $writeable = [
            storage_path(),
            storage_path('logs'),
            storage_path('app'),
        ];
        foreach ($writeable as $path) {
            if (!is_writable($path)) {
                $errors[] = "Directory '{$path}' must be writable.";
            }
        }

        return $errors;
    }

    /**
     * Advance from step 1 to step 2 after passing environment checks.
     */
    public function goToStep2(): void
    {
        if ($this->isInstalled()) {
            return;
        }

        $this->envErrors = $this->checkEnvironment();

        if (empty($this->envErrors)) {
            $this->step = 2;
        }
    }

    // -----------------------------------------------------------------------
    // Step 2 — Database configuration
    // -----------------------------------------------------------------------

    /**
     * Validate DB inputs and test the connection before advancing.
     */
    public function goToStep3(): void
    {
        if ($this->isInstalled()) {
            return;
        }

        // WHY the host/name patterns: both are interpolated into the PDO DSN, so
        // `;` or `=` would inject extra DSN options. Hyphens are valid in MySQL
        // database names (e.g. `s-cart`) and must be accepted.
        $this->validate([
            'dbHost' => ['required', 'max:255', 'regex:/^[a-zA-Z0-9._\-:\[\]]+$/'],
            'dbPort' => 'required|numeric|min:1|max:65535',
            'dbName' => ['required', 'max:64', 'regex:/^[a-zA-Z0-9_\-]+$/'],
            'dbUser' => 'required',
        ]);

        $this->dbError = '';

        try {
            new \PDO(
                "mysql:host={$this->dbHost};port={$this->dbPort};dbname={$this->dbName};charset=utf8mb4",
                $this->dbUser,
                $this->dbPass,
                [\PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\PDOException $e) {
            $this->dbError = 'Cannot connect to database: ' . $e->getMessage();
            return;
        }

        $this->step = 3;
    }

    // -----------------------------------------------------------------------
    // Step 3 — Site & admin config
    // -----------------------------------------------------------------------

    /**
     * Validate site/admin inputs and advance to the installation step.
     */
    public function goToStep4(): void
    {
        if ($this->isInstalled()) {
            return;
        }

        // Limits mirror the admin_user columns (username 100, email 150, name 100).
        $this->validate([
            'siteName'      => 'required|max:100',
            'adminUsername' => 'required|alpha_dash|min:3|max:100',
            'adminEmail'    => 'required|email|max:150',
            'adminPassword' => 'required|min:8|max:100',
            'adminName'     => 'required|max:100',
        ]);

        $this->step = 4;
    }

    // -----------------------------------------------------------------------
    // Step 4 — Run installation
    // -----------------------------------------------------------------------

    /**
     * Write .env, run migrations and seeders, hand the seeded administrator
     * the entered credentials, and write the install marker.
     *
     * Refuses to run on an installed site. On failure the marker is not
     * written (the site stays installable) and only a generic message reaches
     * the browser; details go to the server log.
     *
     * Sets QUEUE_CONNECTION=sync so shared hosts without queue workers work
     * out of the box (US-DEP-002 AC3).
     */
    public function runInstall(): void
    {
        $this->installError = '';

        if ($this->isInstalled()) {
            $this->installError = 'GP247 is already installed.';
            return;
        }

        try {
            $this->writeEnv();

            // WHY: this request booted with the previous .env, and config:clear
            // only deletes the cache file — without this, migrate would run
            // against the old database settings.
            $this->applyDatabaseConfig(GP247_DB_CONNECTION);
            Artisan::call('config:clear');

            Artisan::call('migrate', ['--force' => true]);
            // WHY: a bare class name is prefixed with Database\Seeders\ by
            // Laravel's SeedCommand, which crashed with "Target class
            // [Database\Seeders\DataDefaultSeeder] does not exist". Use the
            // package FQCNs and run the SAME three seeders as the CLI
            // installer (Commands/Install.php) so both paths seed identically.
            Artisan::call('db:seed', ['--class' => \GP247\Core\Database\Seeders\DataDefaultSeeder::class, '--force' => true]);
            Artisan::call('db:seed', ['--class' => \GP247\Core\Database\Seeders\DataStoreSeeder::class, '--force' => true]);
            Artisan::call('db:seed', ['--class' => \GP247\Core\Database\Seeders\DataLocaleSeeder::class, '--force' => true]);

            $this->claimSeededAdmin();

            Storage::disk('local')->put(self::INSTALLED_MARKER, now()->toDateTimeString());

            $this->step = 5;

        } catch (\Throwable $e) {
            Log::error('GP247 web installer failed', ['exception' => $e]);
            $this->installError = 'Installation failed. Details were written to the server log (storage/logs).';
        }
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------

    /**
     * @return \Illuminate\View\View
     */
    public function render(): \Illuminate\View\View
    {
        return view('gp247-admin::livewire.installer-wizard')
            ->layout('gp247-admin::layouts.installer');
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Write DB credentials and shared-host defaults to .env.
     *
     * Values containing spaces or double-quotes are wrapped in double-quotes
     * and escaped. QUEUE_CONNECTION is forced to sync for shared-host compat.
     */
    private function writeEnv(): void
    {
        $envPath = $this->envPath();

        if (!file_exists($envPath)) {
            copy(base_path('.env.example'), $envPath);
        }

        $content = file_get_contents($envPath);

        $replacements = [
            'APP_NAME'         => $this->siteName,
            'DB_HOST'          => $this->dbHost,
            'DB_PORT'          => $this->dbPort,
            'DB_DATABASE'      => $this->dbName,
            'DB_USERNAME'      => $this->dbUser,
            'DB_PASSWORD'      => $this->dbPass,
            'QUEUE_CONNECTION' => 'sync',
        ];

        foreach ($replacements as $key => $value) {
            $safe = $this->escapeEnvValue((string) $value);

            if (preg_match("/^{$key}=/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$safe}", $content);
            } else {
                $content .= "\n{$key}={$safe}";
            }
        }

        file_put_contents($envPath, $content);
    }

    /**
     * Escape a value for safe use in a .env file.
     *
     * WHY: .env values with spaces or special chars must be quoted;
     * double-quotes inside must be escaped to prevent shell/dotenv parse errors.
     */
    private function escapeEnvValue(string $value): string
    {
        if (preg_match('/[\s"\'#\\\\]/', $value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }

        return $value;
    }

    /**
     * Path of the .env file the installer writes.
     *
     * @return string
     */
    protected function envPath(): string
    {
        return base_path('.env');
    }

    /**
     * Apply the entered database settings to the running request.
     *
     * The connection is purged only when a value differs, so an already
     * correct connection (and any transaction on it) is left untouched.
     *
     * @param string $connection Connection name in config/database.php.
     * @return bool True when the settings changed and the connection was purged.
     */
    protected function applyDatabaseConfig(string $connection): bool
    {
        $wanted = [
            'host'     => $this->dbHost,
            'port'     => $this->dbPort,
            'database' => $this->dbName,
            'username' => $this->dbUser,
            'password' => $this->dbPass,
        ];

        $changed = false;
        foreach ($wanted as $key => $value) {
            $configKey = "database.connections.{$connection}.{$key}";
            if ((string) config($configKey) !== $value) {
                config([$configKey => $value]);
                $changed = true;
            }
        }

        if ($changed) {
            DB::purge($connection);
        }

        return $changed;
    }

    /**
     * Hand the administrator account created by DataDefaultSeeder the
     * credentials entered in step 3, so no account keeps the default password.
     *
     * WHY update instead of insert: the seeder already created the account with
     * its Administrator role (admin_role_user); a second account would leave the
     * default one signing in. See ADR installer-deploy_initial-admin-account.
     *
     * @return void
     * @throws \RuntimeException When the seeded account is missing.
     */
    private function claimSeededAdmin(): void
    {
        $admin = AdminUser::find(DataDefaultSeeder::SEED_ADMIN_ID);
        if (!$admin) {
            throw new \RuntimeException('Seeded administrator account ' . DataDefaultSeeder::SEED_ADMIN_ID . ' not found after seeding.');
        }

        $admin->forceFill([
            'username' => $this->adminUsername,
            'name'     => $this->adminName,
            'email'    => $this->adminEmail,
            'password' => Hash::make($this->adminPassword),
            'status'   => 1,
        ])->save();
    }
}
