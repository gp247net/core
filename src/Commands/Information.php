<?php

namespace GP247\Core\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

class Information extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:core-info';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get infomation GP247';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->welcome();
        $this->info(config('gp247.name'));
        $this->info(config('gp247.auth').' <'.config('gp247.email').'>');
        $this->info('- Core: '.config('gp247.core'));
        $this->info('- Core sub-version: '.(gp247_composer_get_package_installed()['gp247/core'] ?? ''));
        $this->info('');
        $this->info('Homepage: '.config('gp247.homepage'));
        $this->info('Github: '.config('gp247.github'));
        $this->info('Facebook: '.config('gp247.facebook'));
        $this->info('API: '.config('gp247-config.env.GP247_LIBRARY_API'));
        $this->info('');
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

        $text .= "\n             Welcome to GP247 ".(gp247_composer_get_package_installed()['gp247/core'] ?? '');
        $text .= "\n";

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $this->line($line);
        }

        return Command::SUCCESS;
    }
}
