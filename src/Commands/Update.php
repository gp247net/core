<?php

namespace GP247\Core\Commands;

use Illuminate\Console\Command;
use Throwable;
use DB;
use Illuminate\Support\Facades\Artisan;

class Update extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:core-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update GP247';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            Artisan::call('db:seed', 
                [
                    '--class' => '\GP247\Core\DB\seeders\DataDefaultSeeder',
                    '--force' => true
                ]
            );
            Artisan::call('db:seed', 
                [
                    '--class' => '\GP247\Core\DB\seeders\DataLocaleSeeder',
                    '--force' => true
                ]
            );
            $this->info('- Update database done!');
        } catch (Throwable $e) {
            gp247_report($e->getMessage());
            echo  json_encode(['error' => 1, 'msg' => $e->getMessage()]);
            exit();
        }
        try {
            Artisan::call('gp247:customize static');
            $this->info('- Update static file done!');
        } catch (Throwable $e) {
            gp247_report($e->getMessage());
            echo  json_encode(['error' => 1, 'msg' => $e->getMessage()]);
            exit();
        }
        $this->info('---------------------');
        $this->info('Core: '.config('gp247.core'));
        $this->info('Core sub-version: '.(gp247_composer_get_package_installed()['gp247/core'] ?? ''));
    }
}
