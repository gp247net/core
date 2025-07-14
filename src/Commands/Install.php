<?php

namespace GP247\Core\Commands;

use Illuminate\Console\Command;
use Throwable;
use DB;
use Illuminate\Support\Facades\Storage;

class Install extends Command
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
     * @return mixed
     */
    public function handle()
    {
        $force = $this->option('force') ?? 0;

        if (!$force) {
            if (!$this->checkGP247Installed()) {
                return Command::FAILURE;
            }
            if ($this->confirm('Are you sure you want to install GP247?')) {
                $this->install();
            } else {
                $this->info('Installation canceled');
            }
        } else {
            $this->install();
        }

    }

    private function welcome() {
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

        return Command::SUCCESS;
    }

    private function checkEnv()
    {
        if (!file_exists(base_path() . "/.env")) {
            $this->fail("File .env not found");
            return false;
        } else if (!config('app.key')) {
            $this->call('key:generate');
        }
        return true;
    }

    private function checkGP247Installed()
    {
        if (\Illuminate\Support\Facades\Storage::disk('local')->exists('gp247-installed.txt')) {
            $this->error("GP247 has been installed");
            $this->fail("If you want to reinstall, please delete the file gp247-installed.txt in the ".\Illuminate\Support\Facades\Storage::disk('local')->path('gp247-installed.txt'));
            return false;
        }
        return true;
    }

    private function install() {

        if (!$this->checkEnv()) {
            return Command::FAILURE;
        }

        $this->call('migrate');
        $this->info('---------------> Migrate default done!');

        \DB::connection(GP247_DB_CONNECTION)->table('migrations')->where('migration', '00_00_00_step1_create_tables_admin')->delete();
        $this->call('migrate', ['--path' => '/vendor/gp247/core/src/DB/migrations/00_00_00_step1_create_tables_admin.php']);
        $this->info('---------------> Migrate schema GP247 done!');

        $this->call('db:seed', ['--class' => '\GP247\Core\DB\seeders\DataDefaultSeeder', '--force' => true]);
        $this->info('---------------> Seeding database GP247 default done!');
        $this->call('db:seed', ['--class' => '\GP247\Core\DB\seeders\DataStoreSeeder', '--force' => true]);
        $this->info('---------------> Seeding database GP247 system done!');
        $this->call('db:seed', ['--class' => '\GP247\Core\DB\seeders\DataLocaleSeeder', '--force' => true]);
        $this->info('---------------> Seeding database GP247 local done!');

        $this->call('vendor:publish', ['--tag' => 'gp247:public-static']);
        $this->call('vendor:publish', ['--tag' => 'gp247:public-vendor']);
        $this->call('vendor:publish', ['--tag' => 'gp247:functions-except']);

        $this->call('storage:link');

        Storage::disk('local')->put('gp247-installed.txt', date('Y-m-d H:i:s'));

        $this->welcome();
    }
}
