<?php

namespace GP247\Core\Support;

use Illuminate\Database\Migrations\Migrator;

/**
 * Records a package's upgrade migrations as already run on a fresh install.
 *
 * A fresh install builds the current schema from the package's base create-tables migration and
 * seeders, which already produce what every file in its upgrade/ folder would. Without a row in
 * the `migrations` table those files would all run again on the first `gp247:update` — slow, and
 * every one of them would have to be right on a clean install too. Like the rows Laravel's
 * `schema:dump` keeps in its dump, this writes the missing rows without running `up()`.
 *
 * Safe only while a fresh install equals a fresh install that runs every upgrade migration;
 * FreshInstallUpgradeEquivalenceTest checks that (RISK-TECH-fresh-install-upgrade-gap).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CLI-fresh-install-upgrade-baseline
 * @aidlc-adr compat-foundation_public-release-migration-policy
 */
class UpgradeMigrationBaseline
{
    /**
     * Write a `migrations` row for every migration file in the folder that has none yet.
     *
     * Rows already present keep their batch; the new rows share the next batch number. A missing
     * or empty folder, or a database without a `migrations` table, records nothing.
     *
     * @param string $dir Absolute path of the package's upgrade/ folder.
     * @return string[] Names recorded by this call, in run order.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CLI-fresh-install-upgrade-baseline
     */
    public static function markApplied(string $dir): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        // WHY the migrator's own file listing: names must match exactly what `migrate --path`
        // would look for, or the first update would still see the files as pending.
        $files = $migrator->getMigrationFiles([$dir]);
        if ($files === []) {
            return [];
        }

        $repository = $migrator->getRepository();
        // WHY the default connection: `migrate --path` in gp247:*-update records its rows there.
        $repository->setSource(null);
        if (!$repository->repositoryExists()) {
            return [];
        }

        $missing = array_values(array_diff(array_keys($files), $repository->getRan()));
        if ($missing === []) {
            return [];
        }

        $batch = $repository->getNextBatchNumber();
        foreach ($missing as $name) {
            $repository->log($name, $batch);
        }

        return $missing;
    }
}
