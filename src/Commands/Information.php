<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;

/**
 * Print GP247 system information (name, author, core version, homepage, links,
 * marketplace API endpoint).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-004
 * @aidlc-adr system-cli_output-contract
 */
class Information extends GP247Command
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
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        $subVersion = gp247_composer_get_package_installed()['gp247/core'] ?? '';

        $this->welcome();
        $this->info(config('gp247.name'));
        $this->info(config('gp247.auth').' <'.config('gp247.email').'>');
        $this->info('- Core: '.config('gp247.core'));
        $this->info('- Core sub-version: '.$subVersion);
        $this->info('');
        $this->info('Homepage: '.config('gp247.homepage'));
        $this->info('Github: '.config('gp247.github'));
        $this->info('Facebook: '.config('gp247.facebook'));
        $this->info('API: '.config('gp247-config.env.GP247_LIBRARY_API'));
        $this->info('');

        return $this->respondSuccess([
            'name'        => config('gp247.name'),
            'author'      => config('gp247.auth'),
            'core'        => config('gp247.core'),
            'sub_version' => $subVersion,
            'homepage'    => config('gp247.homepage'),
            'github'      => config('gp247.github'),
            'facebook'    => config('gp247.facebook'),
            'api'         => config('gp247-config.env.GP247_LIBRARY_API'),
        ]);
    }

    /**
     * Print the ASCII logo banner.
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

        $text .= "\n             Welcome to GP247 ".(gp247_composer_get_package_installed()['gp247/core'] ?? '');
        $text .= "\n";

        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $this->line($line);
        }
    }
}
