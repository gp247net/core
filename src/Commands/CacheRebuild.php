<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;

/**
 * Rebuild GP247 route/config/view caches (wraps gp247_extension_after_update), with
 * the same shared-host soft-degrade the helper already provides.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_output-contract
 * @aidlc-adr system-cli_cache-rebuild-scope
 */
class CacheRebuild extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:cache-rebuild';

    /** @var string */
    protected $description = 'Rebuild GP247 route/config/view caches (after enabling/updating extensions)';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        gp247_extension_after_update();
        $this->info('Cache rebuilt.');
        return $this->respondSuccess(['rebuilt' => true]);
    }
}
