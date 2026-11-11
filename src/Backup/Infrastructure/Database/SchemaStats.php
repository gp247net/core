<?php

namespace GP247\Core\Backup\Infrastructure\Database;

use Illuminate\Database\ConnectionInterface;
use WeakMap;

/**
 * MySQL 8 caches table statistics (row counts, sizes, average row length) in information_schema
 * for up to a day (`information_schema_stats_expiry`, default 86 400 s): a table that grew since is
 * reported at its old size — measured 16 MB for a 996 MB database (mod 20261110T164834). Sizing
 * decisions (free-space check, web limit, page size) need the current figures, so the session that
 * reads them asks for fresh statistics once. MariaDB and MySQL 5.7 have no such variable and report
 * live figures: the statement fails there and is ignored.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class SchemaStats
{
    /** @var WeakMap<object, true>|null PDO handles already set. */
    private static ?WeakMap $done = null;

    /**
     * @param ConnectionInterface $db
     * @return void
     */
    public static function fresh(ConnectionInterface $db): void
    {
        if (!in_array($db->getDriverName(), ['mysql', 'mariadb'], true) || !method_exists($db, 'getPdo')) {
            return;
        }
        self::$done ??= new WeakMap;
        $pdo = $db->getPdo();
        if (isset(self::$done[$pdo])) {
            return;
        }
        self::$done[$pdo] = true;
        try {
            $db->statement('SET SESSION information_schema_stats_expiry = 0');
        } catch (\Throwable $e) {
            // MariaDB / MySQL 5.7: statistics are live already.
        }
    }
}
