<?php

namespace GP247\Core\Backup\Infrastructure\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The connection site tables are read from during one slice of a backup.
 *
 * On MySQL / MariaDB it is a separate connection (same settings as the GP247 one) holding a
 * read-only `START TRANSACTION WITH CONSISTENT SNAPSHOT`: every table read in the slice sees
 * the same instant, while the job bookkeeping (lease, cursor) is committed at once on the main
 * connection. Elsewhere — other drivers, `reader_connection = false`, or a snapshot that could
 * not be opened — it is the GP247 connection without a snapshot, and the archive is recorded
 * as "multi_slice". Nothing is ever written through this connection.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_sliced-job-runner
 */
final class ReaderConnection
{
    public const NAME = 'gp247_backup_reader';

    /**
     * @param ConnectionInterface $db
     * @param bool                $snapshot A consistent snapshot is open on $db.
     * @param bool                $separate $db is this class's own connection (closed by close()).
     */
    private function __construct(
        private readonly ConnectionInterface $db,
        private readonly bool $snapshot,
        private readonly bool $separate,
    ) {
    }

    /**
     * @return self
     */
    public static function open(): self
    {
        $main = DB::connection(GP247_DB_CONNECTION);
        $setting = config('gp247-config.backup.reader_connection');
        if ($setting === false || !in_array($main->getDriverName(), ['mysql', 'mariadb'], true)) {
            return new self($main, false, false);
        }

        $base = config('database.connections.' . GP247_DB_CONNECTION);
        if (!is_array($base)) {
            return new self($main, false, false);
        }
        // WHY: a read replica would answer queries outside the snapshot opened on the writer,
        // so the reader is a plain connection to the write host.
        $base = array_merge($base, is_array($base['write'] ?? null) ? $base['write'] : []);
        unset($base['read'], $base['write'], $base['sticky']);
        try {
            config(['database.connections.' . self::NAME => $base]);
            DB::purge(self::NAME);
            $reader = DB::connection(self::NAME);
            // WHY: a consistent snapshot needs REPEATABLE READ even when the server default is
            // READ COMMITTED; unprepared() because these statements are not preparable everywhere.
            $reader->unprepared('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $reader->unprepared('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            return new self($reader, true, true);
        } catch (\Throwable $e) {
            DB::purge(self::NAME);

            return new self($main, false, false);
        }
    }

    /**
     * @return ConnectionInterface
     */
    public function db(): ConnectionInterface
    {
        return $this->db;
    }

    /**
     * @return bool Every read of this slice sees one consistent snapshot.
     */
    public function hasSnapshot(): bool
    {
        return $this->snapshot;
    }

    /**
     * End the read transaction and drop the separate connection.
     *
     * @return void
     */
    public function close(): void
    {
        if (!$this->separate) {
            return;
        }
        try {
            $this->db->unprepared('ROLLBACK');
        } catch (\Throwable $e) {
            // The connection is being dropped anyway; a failed rollback leaves nothing behind.
        }
        DB::purge(self::NAME);
    }
}
