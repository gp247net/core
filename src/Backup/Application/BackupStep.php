<?php

namespace GP247\Core\Backup\Application;

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
 * A table without a usable key is read in LIMIT / OFFSET pages like any other table, its offset
 * kept between requests (mod 20261110T164834: reading it in one go could never finish on a 30 s
 * host). Within one slice the offsets are stable (one snapshot); a table continued in a later slice
 * may miss or repeat rows written in between — recorded as "no_key_multi_slice:<table>".
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-resumable-run, US-backup-engine-full-backup, US-backup-engine-selective-backup, US-backup-engine-quick-snapshot
 * @aidlc-adr backup-engine_sliced-job-runner, backup-engine_archive-format
 */
final class BackupStep
{
    /** Rows fetched per page shrink so one page stays around this many bytes (wide rows). A page
     *  takes ~2.7 times its text in PHP (measured, mod 20261110T164834): 4 MiB keeps a page ~11 MB. */
    private const PAGE_BYTES = 4_194_304;

    /** Most rows of the first page of any table; the following pages adapt to the bytes really read. */
    private const FIRST_PAGE_ROWS = 100;

    /** Each attempt after a slice killed by the host reads pages this many times smaller. */
    private const KILLED_PAGE_DIVISOR = 4;

    /** Most rows per page for this slice, set when the slice before it was killed while reading. */
    private ?int $limitCap = null;

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
        $cursor = $this->jobs->cursor($job);
        $archiveId = (string) $job->archive_id;
        $dir = $this->vault->archiveDir($archiveId);
        // WHY: the page size saved by a slice the host killed (memory, time) is the one that killed
        // it; reading the same page again would die at the same place on every attempt.
        $this->limitCap = self::limitAfterKill($job, isset($cursor['limit']) ? (int) $cursor['limit'] : null);

        if (!isset($cursor['writer'])) {
            // First slice (or a first slice that died before saving anything): start clean.
            if (!$this->vault->deleteArchive($archiveId)) {
                BackupLog::failure('Archive ' . $archiveId . ': the folder of an earlier attempt could not be cleared.');
            }
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
        if (!$cursor['open']) {
            try {
                $plan = $reader->describe($table['real']);
            } catch (TableMissing $e) {
                // Removed since the job was planned (extension uninstalled): skip, say so.
                $cursor['warnings'][] = 'table_missing:' . $table['real'];
                $cursor['ti']++;

                return true;
            }
            if ($plan['mode'] === TableReader::MODE_SCAN) {
                $cursor['warnings'][] = 'no_key:' . $table['name'];
            }
            $writer->beginTable($table['name'], $plan['columns']);
            $cursor = ['open' => true, 'table_plan' => $plan, 'after' => null, 'offset' => 0, 'table_slice' => $cursor['table_slices'],
                'limit' => self::firstLimit($plan['row_bytes'] ?? null)] + $cursor;
        }
        $plan = $cursor['table_plan'];
        $slice->working = $table['name'];
        $warning = 'no_key_multi_slice:' . $table['name'];
        if ($plan['mode'] === TableReader::MODE_SCAN && ($cursor['table_slice'] ?? null) !== $cursor['table_slices'] && !in_array($warning, $cursor['warnings'], true)) {
            $cursor['warnings'][] = $warning;
        }

        do {
            try {
                $page = $reader->page($table['real'], $plan, CursorValue::unpack($cursor['after']), $cursor['offset'], $this->capped((int) $cursor['limit']));
            } catch (TableMissing $e) {
                $page = ['rows' => [], 'after' => null, 'offset' => $cursor['offset'], 'done' => true];
                $cursor['warnings'][] = 'table_missing:' . $table['real'];
            }
            $bytes = 0;
            foreach ($page['rows'] as $row) {
                $writer->addRow($row);
                $bytes += self::rowBytes($row);
            }
            $cursor['after'] = CursorValue::pack($page['after']);
            $cursor['offset'] = $page['offset'];
            $cursor['limit'] = self::nextLimit(count($page['rows']), $bytes);
            if ($page['done']) {
                $writer->endTable();
                $cursor = ['open' => false, 'after' => null, 'offset' => 0, 'ti' => $cursor['ti'] + 1, 'table_plan' => null] + $cursor;
            }
            $mustStop = $slice->expired();
            if ($mustStop || $slice->saveDue()) {
                $this->save($cursor, $writer, $slice);
            }
            if ($mustStop) {
                return false;
            }
            $done = $page['done'];
            // WHY: drop this page before the next one is fetched — two wide pages alive at once
            // exhausted 128 MB (mod 20261110T164834).
            $page = null;
        } while (!$done);

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
            $slice->working = $entry['key'];
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
            'packages' => SiteVersions::packages(),
            'migrations' => SiteVersions::migrations(),
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
     * Rows per page for a slice picking up after the host killed the one before it.
     *
     * @param object   $job   Job row: attempts and error of the slices before this one.
     * @param int|null $limit Page size saved in the cursor (null: none yet).
     * @return int|null Smaller page size, or null when the slice before did not die reading a page.
     *
     * @aidlc-unit backup-engine
     * @aidlc-story US-backup-engine-resumable-run
     */
    public static function limitAfterKill(object $job, ?int $limit): ?int
    {
        $attempts = (int) ($job->attempts ?? 0);
        if ($attempts < 1 || preg_match('/^(memory_exhausted|time_exceeded):/', (string) ($job->error ?? '')) !== 1) {
            return null;
        }
        $base = $limit !== null && $limit > 0 ? $limit : min(self::pageRows(), self::FIRST_PAGE_ROWS);

        return max(1, intdiv($base, self::KILLED_PAGE_DIVISOR ** min($attempts, 8)));
    }

    /**
     * @param int $limit
     * @return int $limit, no more than the cap of a slice picking up after a kill.
     */
    private function capped(int $limit): int
    {
        return $this->limitCap === null ? $limit : max(1, min($limit, $this->limitCap));
    }

    /**
     * @param int|null $rowBytes Average row size the database reports (null / 0 = unknown).
     * @return int Rows of the first page of a table: small from the start, because a page is held in
     *             memory whole (mod 20261110T164834: a first page of 5 000 rows of ~9 KB exhausted 128 MB).
     *             The reported row size cannot be trusted alone — InnoDB leaves text stored off-page
     *             out of it — so it only lowers this cap further.
     */
    private static function firstLimit(?int $rowBytes): int
    {
        $limit = min(self::pageRows(), self::FIRST_PAGE_ROWS);
        if ($rowBytes !== null && $rowBytes > 0) {
            $limit = min($limit, intdiv(self::PAGE_BYTES, $rowBytes));
        }

        return max(1, $limit);
    }

    /**
     * @param int $rows  Rows in the last page.
     * @param int $bytes Their size.
     * @return int Rows for the next page: as many as fit PAGE_BYTES at the width just read, up to the
     *             configured page. WHY proportional both ways (mod 20261110T164834): a small page that
     *             fit used to jump straight to the full page — 5 000 rows of ~9 KB, 128 MB exhausted.
     */
    private static function nextLimit(int $rows, int $bytes): int
    {
        if ($rows === 0 || $bytes <= 0) {
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
}
