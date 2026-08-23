<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\Storage;

/**
 * First-time installation of the whole GP247 platform (migrate, seed, publish
 * assets, storage link, installed marker).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-005
 * @aidlc-adr system-cli_output-contract
 */
class Install extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:core-install {--force=0}';

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
     * Print the welcome banner after a successful install.
     *
     * @return void
     */
    private function welcome()
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
        $text .= "\n             User/password: admin/admin";
        $text .= "\n";

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $this->line($line);
        }
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

        Storage::disk('local')->put('gp247-installed.txt', date('Y-m-d H:i:s'));

        $this->welcome();

        return $this->respondSuccess([
            'installed' => true,
            'core'      => config('gp247.core'),
        ]);
    }
}
