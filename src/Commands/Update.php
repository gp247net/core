<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Update GP247 after bumping package versions with composer update: re-seed
 * default + locale data safely (never overwriting edited rows) and refresh
 * customized static files.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-005
 * @aidlc-adr system-cli_output-contract
 */
class Update extends GP247Command
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
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        try {
            $this->runArtisan('db:seed', [
                '--class' => '\GP247\Core\Database\Seeders\DataDefaultSeeder',
                '--force' => true,
            ]);
            $this->runArtisan('db:seed', [
                '--class' => '\GP247\Core\Database\Seeders\DataLocaleSeeder',
                '--force' => true,
            ]);
            $this->info('- Update database done!');
        } catch (Throwable $e) {
            gp247_report($e->getMessage());
            return $this->respondFailure('seed_failed', $e->getMessage());
        }

        $core = config('gp247.core');
        $sub = gp247_composer_get_package_installed()['gp247/core'] ?? '';
        $this->info('---------------------');
        $this->info('Core: '.$core);
        $this->info('Core sub-version: '.$sub);

        return $this->respondSuccess([
            'core'        => $core,
            'sub_version' => $sub,
        ]);
    }
}
