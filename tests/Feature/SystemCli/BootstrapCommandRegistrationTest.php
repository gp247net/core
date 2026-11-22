<?php

namespace GP247\Core\Tests\Feature\SystemCli;

use Illuminate\Support\Facades\Artisan;
use GP247\Core\Tests\TestCase;

/**
 * Guards the bootstrap command tier (US-CLI-003):
 * gp247:install / gp247:core-install / gp247:doctor must be registered
 * unconditionally in CoreServiceProvider::initial() so they exist the moment
 * the package is composer-installed — before gp247:core-install has ever run
 * and regardless of the gp247-installed.txt marker.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_command-registration-tiers
 */
class BootstrapCommandRegistrationTest extends TestCase
{
    /**
     * The three bootstrap-tier commands are always registered.
     *
     * @return void
     */
    public function test_bootstrap_commands_are_registered(): void
    {
        $names = array_keys(Artisan::all());
        foreach (['gp247:install', 'gp247:core-install', 'gp247:doctor'] as $cmd) {
            $this->assertContains($cmd, $names, "{$cmd} must be in the bootstrap tier");
        }
    }

    /**
     * Bootstrap-tier command classes must not reference gp247_* helpers, since
     * those helpers only load behind the installed-gate and would fatal
     * pre-install. This static guard enforces the tier's admission criterion.
     *
     * @return void
     */
    public function test_bootstrap_command_sources_do_not_use_gp247_helpers(): void
    {
        $files = [
            'vendor/gp247/core/src/Commands/Install.php',
            'vendor/gp247/core/src/Commands/InstallAll.php',
            'vendor/gp247/core/src/Commands/Doctor.php',
        ];

        foreach ($files as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, "{$relative} should exist");
            $source = file_get_contents($path);

            // WHY: match gp247_* helper *calls* (identifier followed by "("),
            // not the class namespace GP247\Core or command names like
            // "gp247:core-install" which are legitimate pre-install strings.
            $this->assertSame(
                0,
                preg_match('/\bgp247_[a-z0-9_]*\s*\(/i', $source),
                "{$relative} must not call gp247_* helpers (bootstrap tier runs pre-install)"
            );
        }
    }
}
