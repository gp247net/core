<?php

namespace GP247\Core\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Update GP247 after bumping package versions with composer update: run the
 * core upgrade migrations, then re-seed default + locale data safely (never
 * overwriting edited rows).
 *
 * Since GP247 went public at v2.1, every breaking change ships an idempotent
 * migration delivered by `gp247:update` (ADR compat-foundation_public-release-migration-policy,
 * NFR-MAINT-breaking-change-migration). Core was the one package with no such
 * path — it only re-seeded — so a schema or data change in core had no way of
 * reaching an installed site. This command now mirrors gp247:shop-update /
 * gp247:front-update.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-005, US-CLI-upgrade-migration-delivery
 * @aidlc-adr system-cli_output-contract
 * @aidlc-adr compat-foundation_public-release-migration-policy
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
        // WHY migrate BEFORE seeding: the seeders write default rows into whatever
        // schema is current. Seeding first would populate the old shape and leave
        // the migration to clean up after it. Only the upgrade/ folder is run —
        // never the sibling create-tables migration, which would wipe the database
        // (same guard as gp247:shop-update / gp247:front-update).
        try {
            $this->runArtisan('migrate', [
                '--path'  => '/vendor/gp247/core/src/Database/Migrations/upgrade',
                '--force' => true,
            ]);
            $this->info('- Core upgrade migrations done!');
        } catch (Throwable $e) {
            gp247_report($e->getMessage());
            return $this->respondFailure('upgrade_failed', 'Core upgrade failed: '.$e->getMessage());
        }

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
            // Part of the output contract: consumers (CI, the installer, a site
            // owner reading --json) can tell that the upgrade path actually ran,
            // not just the seeders (MC-public-upgrade-path metric 4).
            'migrated'    => true,
        ]);
    }
}
