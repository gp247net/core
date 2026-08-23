<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrate a safe post-`composer update` refresh for a live site: core-update,
 * then shop-update when the shop is installed, optional language overwrite, and a
 * cache rebuild. Never runs a destructive (re)install step.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_output-contract
 */
class UpdateAll extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:update
        {--overwrite-lang : Also run gp247:language-update (overwrites edited translations)}';

    /** @var string */
    protected $description = 'Update GP247 after composer update (core [+shop], safe for live sites)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $done = [];

        $this->info('==> gp247:core-update');
        if ($this->runArtisan('gp247:core-update') !== Command::SUCCESS) {
            return $this->respondFailure('core_update_failed', 'gp247:core-update failed', ['completed' => $done]);
        }
        $done[] = 'gp247:core-update';

        // WHY: only touch the shop when it is actually installed (create-tables
        // migration recorded) — running its upgrade otherwise is meaningless.
        if ($this->shopInstalled()) {
            $this->info('==> gp247:shop-update');
            if ($this->runArtisan('gp247:shop-update') !== Command::SUCCESS) {
                return $this->respondFailure('shop_update_failed', 'gp247:shop-update failed', ['completed' => $done]);
            }
            $done[] = 'gp247:shop-update';
        }

        if ($this->option('overwrite-lang')) {
            $this->info('==> gp247:language-update');
            $this->runArtisan('gp247:language-update');
            $done[] = 'gp247:language-update';
        }

        $this->info('==> gp247:cache-rebuild');
        $this->runArtisan('gp247:cache-rebuild');
        $done[] = 'gp247:cache-rebuild';

        return $this->respondSuccess(['completed' => $done]);
    }

    /**
     * Whether the shop module is installed (its create-tables migration ran).
     *
     * @return bool
     */
    protected function shopInstalled(): bool
    {
        try {
            return DB::connection(GP247_DB_CONNECTION)
                ->table('migrations')
                ->where('migration', '00_00_00_create_tables_shop')
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
