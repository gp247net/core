<?php

namespace GP247\Core\PersonalData\Application\Export;

use Closure;
use GP247\Core\PersonalData\Application\Erasure\ErasureSteps;
use GP247\Core\PersonalData\Application\Events\PersonalDataExportReady;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SourceQuery;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\Export\ExportFormat;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\Export\ExportStorage;
use GP247\Core\PersonalData\Infrastructure\PersonalDataLanguage;

/**
 * The steps of one data package, resumable at any chunk:
 *
 *  1. read — every declared source that exports columns, chunk by chunk by ascending key: the
 *     subject's rows, only the exported columns, appended to one NDJSON file per source. The
 *     cursor keeps the size of each file, so a chunk written by a request that died before its
 *     checkpoint is cut off and read again — never duplicated;
 *  2. pack — the files become the ZIP (PackageWriter), the package is ready for 24 hours and the
 *     working files are removed;
 *  3. done.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class ExportSteps
{
    public const READ = 'read';
    public const PACK = 'pack';
    public const DONE = ErasureSteps::DONE;
    public const HOURS = 24;

    private const CHUNK = 500;

    /**
     * @param ExportRepository $exports
     * @param PackageWriter    $writer
     */
    public function __construct(private readonly ExportRepository $exports, private readonly PackageWriter $writer)
    {
    }

    /**
     * @param DataSubject $subject  Already expanded with the account's emails.
     * @param string      $exportId
     * @param string|null $requestId
     * @return array<string, mixed> Cursor of a new package.
     */
    public static function start(DataSubject $subject, string $exportId, ?string $requestId): array
    {
        $tables = [];
        foreach (PersonalDataRegistry::sources() as $source) {
            if (ExportFormat::columnsOf($source) !== [] && SourceQuery::sourceExists($source)) {
                $tables[] = $source->table;
            }
        }

        return [
            'subject' => ['customer' => $subject->customerId, 'emails' => $subject->emails],
            'export' => $exportId,
            'request' => $requestId,
            'locale' => app()->getLocale(),
            'tables' => $tables,
            'phase' => self::READ,
            'index' => 0,
            'last' => null,
            'bytes' => 0,
            'counts' => [],
        ];
    }

    /**
     * @param array<string, mixed>                 $cursor
     * @param float                                $deadline   microtime(true) limit.
     * @param Closure(array<string, mixed>): void $checkpoint Saves the cursor after each chunk.
     * @return array<string, mixed>
     */
    public function advance(array $cursor, float $deadline, Closure $checkpoint): array
    {
        while ($cursor['phase'] !== self::DONE && microtime(true) < $deadline) {
            if ($cursor['phase'] === self::READ) {
                $cursor = $this->readChunk($cursor);
                $checkpoint($cursor);
                if ($cursor['phase'] === self::PACK) {
                    // WHY: packing reads every file again; it starts a slice of its own so it gets
                    // the whole time budget, not what reading left of it.
                    break;
                }
                continue;
            }
            $cursor = $this->pack($cursor);
            $checkpoint($cursor);
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    private function readChunk(array $cursor): array
    {
        $table = $cursor['tables'][$cursor['index']] ?? null;
        if ($table === null) {
            return array_merge($cursor, ['phase' => self::PACK]);
        }
        $source = PersonalDataRegistry::source((string) $table);
        $columns = $source === null ? [] : ExportFormat::columnsOf($source);
        if ($source === null || $columns === [] || !SourceQuery::sourceExists($source)) {
            return $this->nextSource($cursor);
        }
        $query = SourceQuery::forSubject($source, $this->subject($cursor));
        if ($cursor['last'] !== null) {
            $query->where($source->key, '>', $cursor['last']);
        }
        $rows = $query->orderBy($source->key)->limit(self::CHUNK)
            ->get(array_values(array_unique(array_merge([$source->key], $columns))))->map(fn ($r) => (array) $r)->all();

        $path = ExportStorage::workDir((string) $cursor['export']) . DIRECTORY_SEPARATOR . ExportFormat::fileName((string) $table) . '.ndjson';
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('The data package cannot be written.');
        }
        try {
            // WHY: drop what a slice that died after writing, before saving its cursor, appended.
            ftruncate($handle, (int) $cursor['bytes']);
            fseek($handle, 0, SEEK_END);
            foreach ($rows as $row) {
                $line = [];
                foreach ($columns as $column) {
                    $line[$column] = $row[$column] ?? null;
                }
                fwrite($handle, json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
            fflush($handle);
            $bytes = ftell($handle);
        } finally {
            fclose($handle);
        }
        $counts = (array) $cursor['counts'];
        $counts[$table] = (int) ($counts[$table] ?? 0) + count($rows);
        $cursor = array_merge($cursor, ['counts' => $counts, 'bytes' => (int) $bytes]);
        if (count($rows) < self::CHUNK) {
            return $this->nextSource($cursor);
        }

        return array_merge($cursor, ['last' => end($rows)[$source->key]]);
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    private function pack(array $cursor): array
    {
        $exportId = (string) $cursor['export'];
        $export = $this->exports->find($exportId);
        if ($export === null || (string) $export->status !== ExportRepository::BUILDING) {
            // WHY: already packed by a slice that died before saving its cursor, or discarded
            // because the customer is being erased: never build it again.
            return array_merge($cursor, ['phase' => self::DONE, 'subject' => null]);
        }
        $previous = app()->getLocale();
        // WHY: a slice may run in another person's request (an administrator's page); the package
        // speaks the language the customer used when asking.
        app()->setLocale((string) ($cursor['locale'] ?? $previous));
        try {
            $parts = [];
            foreach ((array) $cursor['tables'] as $table) {
                $source = PersonalDataRegistry::source((string) $table);
                if ($source === null) {
                    continue;
                }
                $parts[] = [
                    'table' => (string) $table,
                    'file' => ExportFormat::fileName((string) $table),
                    'label' => PersonalDataLanguage::label($source->label()) ?: (string) $table,
                    'columns' => ExportFormat::columnsOf($source),
                    'rows' => (int) ($cursor['counts'][$table] ?? 0),
                ];
            }
            $texts = [
                'title' => (string) gp247_language_render('personal_data.export.title'),
                'intro' => (string) gp247_language_render('personal_data.export.intro'),
                'generated' => (string) gp247_language_render('personal_data.export.generated', ['date' => now()->toDateTimeString()]),
                'readme' => (string) gp247_language_render('personal_data.export.readme'),
                'empty' => (string) gp247_language_render('personal_data.export.empty'),
            ];
            $size = $this->writer->write(ExportStorage::workDir($exportId), $parts, $texts, ExportStorage::zipPath($exportId));
        } finally {
            app()->setLocale($previous);
        }
        if (!$this->exports->markReady($exportId, $size, now()->addHours(self::HOURS))) {
            // WHY: discarded while packing (the customer is being erased): the file must not stay.
            ExportStorage::remove($exportId);

            return array_merge($cursor, ['phase' => self::DONE, 'subject' => null]);
        }
        // WHY after the status: a slice dying here leaves a ready package; the folder goes at clean-up.
        ExportStorage::removeWorkDir($exportId);
        try {
            event(new PersonalDataExportReady($exportId, $cursor['request'] === null ? null : (string) $cursor['request'], array_map('intval', (array) $cursor['counts'])));
        } catch (\Throwable $e) {
            // WHY: the package is ready whatever a listener does; the request is closed by the next check.
            if (function_exists('gp247_report')) {
                gp247_report('Personal data export listener failed: ' . $e::class);
            }
        }

        return array_merge($cursor, ['phase' => self::DONE, 'subject' => null]);
    }

    /**
     * @param array<string, mixed> $cursor
     * @return array<string, mixed>
     */
    private function nextSource(array $cursor): array
    {
        return array_merge($cursor, ['index' => (int) $cursor['index'] + 1, 'last' => null, 'bytes' => 0]);
    }

    /**
     * @param array<string, mixed> $cursor
     * @return DataSubject
     */
    private function subject(array $cursor): DataSubject
    {
        $data = (array) ($cursor['subject'] ?? []);
        $emails = array_map('strval', (array) ($data['emails'] ?? []));
        if (($data['customer'] ?? null) !== null) {
            return DataSubject::customer((string) $data['customer'], $emails);
        }

        return $emails === [] ? DataSubject::guest('unknown@invalid') : DataSubject::guest($emails[0])->withEmails($emails);
    }
}
