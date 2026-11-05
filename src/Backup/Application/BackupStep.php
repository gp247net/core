<?php

namespace GP247\Core\Backup\Application;

use Composer\InstalledVersions;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\FileGroup;
use GP247\Core\Backup\Infrastructure\Database\ReaderConnection;
use GP247\Core\Backup\Infrastructure\Database\TableMissing;
use GP247\Core\Backup\Infrastructure\Database\TableReader;
use GP247\Core\Backup\Infrastructure\Files\FileCollector;
use GP247\Core\Backup\Infrastructure\Format\ArchiveWriter;
use GP247\Core\Backup\Infrastructure\Storage\LocalVault;
use GP247\Core\Backup\Infrastructure\Store\ArchiveRepository;
use GP247\Core\Backup\Infrastructure\Store\JobRepository;
use Illuminate\Support\Facades\DB;

/**
 * One slice of a backup job: tables (keyset pages from the reader connection), then file
 * groups (chunks), then the closing manifest + signed header. Stops at the deadline on a page /
 * chunk boundary with the writer state and the read position saved in the job cursor.
 *
 * Consistency: "consistent" only when every table was read inside ONE database snapshot (the
 * CLI runs the whole job as one slice; a web backup qualifies when its tables fit in its first
 * slice); otherwise "multi_slice".
 *
 * A table without a usable key is read in one go (offsets are not stable between requests):
 * it is not started once half the budget is used, and while it is read the saved cursor stays
 * the one from before the table — a request killed mid-table restarts that table from zero.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-full-backup, US-backup-engine-selective-backup, US-backup-engine-quick-snapshot
 * @aidlc-adr backup-engine_sliced-job-runner, backup-engine_archive-format
 */
final class BackupStep
{
    /** Rows fetched per page shrink so one page stays around this many bytes (wide rows). */
    private const PAGE_BYTES = 8_388_608;

    /** Work was done in this slice (a table that cannot be split may still start first). */
    private bool $progressed = false;

    /**
     * @param JobRepository     $jobs
     * @param ArchiveRepository $archives
     * @param KeyService        $keys
     * @param LocalVault        $vault
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly ArchiveRepository $archives,
        private readonly KeyService $keys,
        private readonly LocalVault $vault,
    ) {
    }

    /**
     * @param object       $job Job row (lease held).
     * @param SliceContext $slice
     * @return bool True when the archive is closed and its verify job queued.
     * @throws LeaseLost
     */
    public function run(object $job, SliceContext $slice): bool
    {
        $this->progressed = false;
        $cursor = $this->jobs->cursor($job);
        $archiveId = (string) $job->archive_id;
        $dir = $this->vault->archiveDir($archiveId);

        if (!isset($cursor['writer'])) {
            // First slice (or a first slice that died before saving anything): start clean.
            $this->vault->deleteArchive($archiveId);
            $writer = ArchiveWriter::start($dir, $this->keys->keyRing(), [
                'archive_id' => $archiveId,
                'segment_rows' => self::pageRows(),
            ]);
            $cursor += ['phase' => 'tables', 'ti' => 0, 'fi' => 0, 'open' => false, 'after' => null, 'offset' => 0, 'limit' => null,
                'fpath' => null, 'foffset' => 0, 'fsize' => null, 'fmtime' => null, 'table_slices' => 0, 'snapshot' => true, 'warnings' => []];
        } else {
            $writer = ArchiveWriter::resume($dir, $cursor['writer']);
        }

        if ($cursor['phase'] === 'tables' && !$this->tables($cursor, $writer, $slice)) {
            return false;
        }
        if ($cursor['phase'] === 'files' && !$this->files($cursor, $writer, $slice)) {
            return false;
        }

        $this->close($job, $cursor, $writer, $slice);

        return true;
    }

    /**
     * @param array         $cursor
     * @param ArchiveWriter $writer
     * @param SliceContext  $slice
     * @return bool True when every table is written.
     */
    private function tables(#[\SensitiveParameter] array &$cursor, ArchiveWriter $writer, SliceContext $slice): bool
    {
        $tables = $cursor['plan']['tables'];
        if ($cursor['ti'] < count($tables)) {
            $reader = ReaderConnection::open();
            try {
                $cursor['table_slices']++;
                $cursor['snapshot'] = $cursor['snapshot'] && $reader->hasSnapshot();
                $tableReader = new TableReader($reader->db());
                while ($cursor['ti'] < count($tables)) {
                    if (!$this->table($cursor, $writer, $slice, $tableReader, $tables[$cursor['ti']])) {
                        return false;
                    }
                }
            } finally {
                $reader->close();
            }
        }
        $cursor['phase'] = 'files';

        return true;
    }

    /**
     * Write (the rest of) one table.
     *
     * @param array                       $cursor
     * @param ArchiveWriter               $writer
     * @param SliceContext                $slice
     * @param TableReader                 $reader
     * @param array{name: string, real: string} $table
     * @return bool False when the slice must stop (progress saved).
     */
    private function table(#[\SensitiveParameter] array &$cursor, ArchiveWriter $writer, SliceContext $slice, TableReader $reader, array $table): bool
    {
        $restart = null;
        if (!$cursor['open']) {
            try {
                $plan = $reader->describe($table['real']);
            } catch (TableMissing $e) {
                // Removed since the job was planned (extension uninstalled): skip, say so.
                $cursor['warnings'][] = 'table_missing:' . $table['real'];
                $cursor['ti']++;

                return true;
            }
            if ($plan['mode'] === TableReader::MODE_SCAN && $this->progressed && $slice->halfSpent()) {
                $this->save($cursor, $writer, $slice);

                return false;
            }
            if ($plan['mode'] === TableReader::MODE_SCAN) {
                // The cursor a killed request falls back to: before this table.
                $cursor['writer'] = $writer->state();
                $restart = $cursor;
                $cursor['warnings'][] = 'no_key:' . $table['name'];
            }
            $writer->beginTable($table['name'], $plan['columns']);
            $cursor = ['open' => true, 'table_plan' => $plan, 'after' => null, 'offset' => 0, 'limit' => self::pageRows()] + $cursor;
        }
        $plan = $cursor['table_plan'];

        do {
            try {
                $page = $reader->page($table['real'], $plan, CursorValue::unpack($cursor['after']), $cursor['offset'], (int) $cursor['limit']);
            } catch (TableMissing $e) {
                $page = ['rows' => [], 'after' => null, 'offset' => $cursor['offset'], 'done' => true];
                $cursor['warnings'][] = 'table_missing:' . $table['real'];
            }
            $bytes = 0;
            foreach ($page['rows'] as $row) {
                $writer->addRow($row);
                $bytes += self::rowBytes($row);
            }
            $this->progressed = true;
            $cursor['after'] = CursorValue::pack($page['after']);
            $cursor['offset'] = $page['offset'];
            $cursor['limit'] = self::nextLimit(count($page['rows']), $bytes);
            if ($page['done']) {
                $writer->endTable();
                $cursor = ['open' => false, 'after' => null, 'offset' => 0, 'ti' => $cursor['ti'] + 1, 'table_plan' => null] + $cursor;
            }
            if ($restart !== null && !$page['done']) {
                // Mid-scan: keep the lease alive, but never record a scan offset.
                if ($slice->saveDue()) {
                    $slice->save($restart);
                }
                continue;
            }
            $mustStop = $slice->expired();
            if ($mustStop || $slice->saveDue()) {
                $this->save($cursor, $writer, $slice);
            }
            if ($mustStop) {
                return false;
            }
        } while (!$page['done']);

        return true;
    }

    /**
     * @param array         $cursor
     * @param ArchiveWriter $writer
     * @param SliceContext  $slice
     * @return bool True when every file group is written.
     */
    private function files(#[\SensitiveParameter] array &$cursor, ArchiveWriter $writer, SliceContext $slice): bool
    {
        $groups = $cursor['plan']['files'];
        $vault = $this->vault->root();
        while ($cursor['fi'] < count($groups)) {
            $entry = $groups[$cursor['fi']];
            $collector = new FileCollector(new FileGroup($entry['key'], $entry['path'], $entry['exclude']), FileCollector::CHUNK_BYTES, $vault);
            if (!$cursor['open']) {
                $writer->beginFiles($entry['key']);
                $cursor['open'] = true;
            }
            $resumePath = CursorValue::unpack($cursor['fpath']);
            foreach ($collector->chunks($resumePath, (int) $cursor['foffset']) as $chunk) {
                if ($chunk['path'] === $resumePath && $chunk['offset'] > 0
                    && ($chunk['size'] !== $cursor['fsize'] || $chunk['mtime'] !== $cursor['fmtime'])) {
                    $cursor['warnings'][] = CursorValue::label('changed:' . $entry['key'] . '/' . $chunk['path']);
                }
                $writer->addFileChunk($chunk['path'], $chunk['offset'], $chunk['size'], $chunk['mtime'], $chunk['data']);
                $this->progressed = true;
                $next = $chunk['offset'] + strlen($chunk['data']);
                $cursor['fpath'] = CursorValue::pack($chunk['path']);
                $cursor['foffset'] = $next >= $chunk['size'] ? -1 : $next;
                $cursor['fsize'] = $chunk['size'];
                $cursor['fmtime'] = $chunk['mtime'];
                $mustStop = $slice->expired();
                if ($mustStop || $slice->saveDue()) {
                    self::mergeWarnings($cursor, $collector);
                    $this->save($cursor, $writer, $slice);
                }
                if ($mustStop) {
                    return false;
                }
            }
            self::mergeWarnings($cursor, $collector);
            $writer->endFiles();
            $cursor = ['open' => false, 'fpath' => null, 'foffset' => 0, 'fsize' => null, 'fmtime' => null, 'fi' => $cursor['fi'] + 1] + $cursor;
        }
        $cursor['phase'] = 'finish';

        return true;
    }

    /**
     * Manifest + signed header, catalogue row to "verifying", verify job queued — the last two
     * in one transaction so an archive never waits for a verification nobody will run. The lease
     * is checked first: a cancelled job never gets a signed header.
     *
     * @param object        $job
     * @param array         $cursor
     * @param ArchiveWriter $writer
     * @param SliceContext  $slice
     * @return void
     */
    private function close(object $job, #[\SensitiveParameter] array $cursor, ArchiveWriter $writer, SliceContext $slice): void
    {
        $this->save($cursor, $writer, $slice);
        $plan = $cursor['plan'];
        $consistency = $cursor['table_slices'] <= 1 && $cursor['snapshot'] ? Archive::CONSISTENT : Archive::MULTI_SLICE;
        $state = $writer->state();
        $dek = $writer->dataKey();
        $writer->finish([
            'kind' => $plan['kind'],
            'reason' => $plan['reason'],
            'scope' => $plan['scope'],
            'groups' => $plan['groups'],
            'raw_tables' => $plan['raw'],
            'consistency' => $consistency,
            'via' => $plan['via'],
            'prefix' => (string) GP247_DB_PREFIX,
            'packages' => self::packages(),
            'migrations' => self::migrations(),
            'warnings' => array_values(array_unique(array_map([CursorValue::class, 'label'], $cursor['warnings']))),
        ], $this->keys->signingKey());

        $archiveId = (string) $job->archive_id;
        $parts = count($writer->state()['parts']);
        DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($job, $archiveId, $consistency, $parts, $dek, $slice, $state) {
            $this->archives->update($archiveId, [
                'status' => Archive::STATUS_VERIFYING,
                'consistency' => $consistency,
                'size' => $this->vault->archiveBytes($archiveId),
                'parts' => $parts,
                'finished_at' => now(),
            ]);
            if (!$this->jobs->finish((int) $job->id, $slice->owner, Archive::JOB_DONE)) {
                throw new LeaseLost;
            }
            // What the header must carry: the verify step checks it, not only the data.
            $this->jobs->create(Archive::JOB_VERIFY, $archiveId, [
                'dek' => base64_encode($dek),
                'dek_wrapped' => $state['dek_wrapped'],
                'key_id' => $state['key_id'],
            ]);
        });
    }

    /**
     * @param array         $cursor
     * @param ArchiveWriter $writer
     * @param SliceContext  $slice
     * @return void
     */
    private function save(#[\SensitiveParameter] array &$cursor, ArchiveWriter $writer, SliceContext $slice): void
    {
        $cursor['writer'] = $writer->state();
        $cursor['warnings'] = array_values(array_unique(array_map([CursorValue::class, 'label'], $cursor['warnings'])));
        $slice->save($cursor);
    }

    /**
     * @param array         $cursor
     * @param FileCollector $collector
     * @return void
     */
    private static function mergeWarnings(array &$cursor, FileCollector $collector): void
    {
        foreach ($collector->warnings() as $warning) {
            $cursor['warnings'][] = CursorValue::label($warning);
        }
    }

    /**
     * @param list<mixed> $row
     * @return int Approximate size of a row.
     */
    private static function rowBytes(array $row): int
    {
        $bytes = 0;
        foreach ($row as $value) {
            $bytes += is_string($value) ? strlen($value) : 8;
        }

        return $bytes;
    }

    /**
     * @param int $rows  Rows in the last page.
     * @param int $bytes Their size.
     * @return int Rows for the next page: the configured page, smaller for wide rows.
     */
    private static function nextLimit(int $rows, int $bytes): int
    {
        if ($rows === 0 || $bytes <= self::PAGE_BYTES) {
            return self::pageRows();
        }

        return max(1, min(self::pageRows(), intdiv(self::PAGE_BYTES * $rows, $bytes)));
    }

    /**
     * @return int
     */
    private static function pageRows(): int
    {
        return max(1, min(5000, (int) config('gp247-config.backup.page_rows', 5000)));
    }

    /**
     * @return array<string, string> Package => version, for compatibility checks at restore.
     */
    private static function packages(): array
    {
        $packages = ['gp247/core' => (string) config('gp247.core')];
        foreach (['gp247/front', 'gp247/shop', 'gp247/mcp'] as $package) {
            try {
                if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
                    $packages[$package] = (string) InstalledVersions::getPrettyVersion($package);
                }
            } catch (\Throwable $e) {
                // Version unknown: restore falls back to the migration list.
            }
        }

        return $packages;
    }

    /**
     * @return list<string> Migrations run on this site (names only).
     */
    private static function migrations(): array
    {
        $setting = config('database.migrations');
        $table = (string) (is_array($setting) ? ($setting['table'] ?? 'migrations') : ($setting ?: 'migrations'));
        try {
            return DB::connection(GP247_DB_CONNECTION)->table($table)->orderBy('id')->pluck('migration')->map(fn ($m) => (string) $m)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
