<?php

namespace GP247\Core\Commands;

use Illuminate\Console\Command;
use Throwable;
use DB;

class Customize extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:customize {obj?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Customize obj in GP247';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $obj = $this->argument('obj');
        switch ($obj) {
            case 'config':
                $this->call('vendor:publish', ['--tag' => 'gp247:config']);
                $this->call('vendor:publish', ['--tag' => 'gp247:functions-except']);
                break;

            case 'lfm':
                $this->call('vendor:publish', ['--tag' => 'gp247:config-lfm']);
                break;

            case 'view':
                $this->call('vendor:publish', ['--tag' => 'gp247:view-core']);
                $this->call('vendor:publish', ['--tag' => 'gp247:view-front']);
                break;

            case 'static':
                $this->call('vendor:publish', ['--tag' => 'gp247:public-static']);
                $this->call('vendor:publish', ['--tag' => 'gp247:public-vendor']);
                break;

            default:
                $this->info('Nothing');
                break;
        }
    }
}
