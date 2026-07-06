<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 *   4 — Running installation (migrate, seed, create admin, write flag)
 *   5 — Done
 *
 * @aidlc-unit installer-deploy
 * @aidlc-story US-DEP-001
 * @aidlc-story US-DEP-002
 */
class InstallerWizard extends Component
{
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
        if (Storage::disk('local')->exists('gp247-installed.txt')) {
            $this->redirect(url(config('gp247.admin_prefix', 'gp247_admin') . '/auth/login'));
        }
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

        // PHP version >= 8.2
        if (PHP_VERSION_ID < 80200) {
            $errors[] = 'PHP >= 8.2 is required. Current version: ' . PHP_VERSION;
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
        $this->validate([
            'dbHost' => 'required',
            'dbPort' => 'required|numeric|min:1|max:65535',
            'dbName' => ['required', 'regex:/^[a-zA-Z0-9_]+$/'],
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
        $this->validate([
            'siteName'      => 'required|max:100',
            'adminEmail'    => 'required|email|max:255',
            'adminPassword' => 'required|min:8|max:100',
            'adminName'     => 'required|max:100',
        ]);

        $this->step = 4;
    }

    // -----------------------------------------------------------------------
    // Step 4 — Run installation
    // -----------------------------------------------------------------------

    /**
     * Write .env, run migrations and seeders, create the admin account, and
     * write the gp247-installed.txt flag to complete installation.
     *
     * Sets QUEUE_CONNECTION=sync so shared hosts without queue workers work
     * out of the box (US-DEP-002 AC3).
     */
    public function runInstall(): void
    {
        $this->installError = '';

        try {
            $this->writeEnv();

            // Reload config so new DB creds are in effect.
            Artisan::call('config:clear');

            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => 'DataDefaultSeeder', '--force' => true]);

            $this->createAdminUser();

            Storage::disk('local')->put('gp247-installed.txt', now()->toDateTimeString());

            $this->step = 5;

        } catch (\Throwable $e) {
            $this->installError = 'Installation failed: ' . $e->getMessage();
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
        $envPath = base_path('.env');

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
     * Insert the initial administrator account using validated inputs.
     *
     * WHY: The GP247 admin user table uses UUID primary keys (no autoincrement).
     * We insert directly because the GP247 admin auth model is not available
     * until after the seeder runs and the framework fully boots.
     */
    private function createAdminUser(): void
    {
        $table = config('gp247.admin_table', 'gp247_admins');

        // Skip if an admin already exists (seeder may have created one).
        if (DB::table($table)->count() > 0) {
            return;
        }

        DB::table($table)->insert([
            'id'         => (string) Str::uuid(),
            'username'   => Str::slug($this->adminName, '_'),
            'email'      => $this->adminEmail,
            'password'   => Hash::make($this->adminPassword),
            'name'       => $this->adminName,
            'status'     => 1,
            'role_id'    => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
