<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Console\Command;

/**
 * Orchestrate a full GP247 install in the correct order: core, then optionally
 * the front (storefront) and shop modules, then optional sample data. Each step
 * delegates to the existing single-purpose command; a failing step aborts.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_output-contract
 */
class InstallAll extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:install
        {--with-front : Also install the storefront (front) module}
        {--with-shop : Also install the shop module (implies front)}
        {--sample : Seed demo shop data (dev only, wipes shop data)}
        {--force=0 : Unattended install (skip confirmation / already-installed check)}';

    /** @var string */
    protected $description = 'Install GP247 end-to-end (core [+front] [+shop] [+sample])';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $force = $this->option('force') ? 1 : 0;
        $withShop = (bool) $this->option('with-shop');
        $withFront = (bool) $this->option('with-front') || $withShop;

        $steps = [];
        $steps[] = ['gp247:core-install', ['--force' => $force]];
        if ($withFront) {
            $steps[] = ['gp247:front-install', []];
        }
        if ($withShop) {
            $steps[] = ['gp247:shop-install', []];
        }
        if ($this->option('sample')) {
            $steps[] = ['gp247:shop-sample', []];
        }

        $done = [];
        foreach ($steps as [$command, $args]) {
            $this->info('==> '.$command);
            $code = $this->runArtisan($command, $args);
            if ($code !== Command::SUCCESS) {
                return $this->respondFailure('step_failed', 'Step failed: '.$command, [
                    'completed' => $done,
                    'failed'    => $command,
                ]);
            }
            $done[] = $command;
        }

        return $this->respondSuccess(['completed' => $done]);
    }
}
