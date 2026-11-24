<?php

namespace GP247\Core\PersonalData\Application\Export;

use GP247\Core\PersonalData\Domain\Export\ExportFormat;

/**
 * Turns the rows collected for a customer (one NDJSON file per source, in the package's working
 * folder) into the ZIP the customer downloads:
 *
 *  - data.json    every source with its rows, machine readable;
 *  - csv/*.csv    one file per source, UTF-8 with BOM so spreadsheets read accents right,
 *                 cells never read as formulas;
 *  - index.html   the same data as a readable page (inline style, every value escaped);
 *  - README.txt   what the files are.
 *
 * Every file is written as a stream: memory does not grow with the number of rows.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-export-package
 * @aidlc-adr personal-data-registry_erasure-job
 */
final class PackageWriter
{
    /**
     * @param string                                                                              $workDir
     * @param list<array{table: string, file: string, label: string, columns: list<string>, rows: int}> $parts
     * @param array{title: string, intro: string, generated: string, readme: string, empty: string}  $texts Already translated.
     * @param string                                                                              $zipPath
     * @return int Size of the ZIP in bytes.
     * @throws \RuntimeException When ZIP is not available on this server or a file cannot be written.
     */
    public function write(string $workDir, array $parts, array $texts, string $zipPath): int
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is missing.');
        }
        // WHY a folder and a ZIP name of its own: a second slice packing the same package at the
        // same time (lease taken over) must not truncate the files this one is still zipping.
        $run = bin2hex(random_bytes(6));
        $outDir = $workDir . DIRECTORY_SEPARATOR . 'pack-' . $run;
        $csvDir = $outDir . DIRECTORY_SEPARATOR . 'csv';
        if (!is_dir($csvDir) && !mkdir($csvDir, 0700, true) && !is_dir($csvDir)) {
            throw new \RuntimeException('The folder of the data package cannot be written.');
        }
        $this->writeJson($workDir, $outDir, $parts, $texts);
        foreach ($parts as $part) {
            $this->writeCsv($workDir, $csvDir, $part);
        }
        $this->writeHtml($workDir, $outDir, $parts, $texts);
        file_put_contents($outDir . DIRECTORY_SEPARATOR . 'README.txt', $texts['readme'] . "\r\n");

        $partial = $zipPath . '.' . $run . '.part';
        $zip = new \ZipArchive();
        if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The data package cannot be written.');
        }
        $zip->addFile($outDir . DIRECTORY_SEPARATOR . 'data.json', 'data.json');
        $zip->addFile($outDir . DIRECTORY_SEPARATOR . 'index.html', 'index.html');
        $zip->addFile($outDir . DIRECTORY_SEPARATOR . 'README.txt', 'README.txt');
        foreach ($parts as $part) {
            $zip->addFile($csvDir . DIRECTORY_SEPARATOR . $part['file'] . '.csv', 'csv/' . $part['file'] . '.csv');
        }
        if ($zip->close() !== true || !rename($partial, $zipPath)) {
            @unlink($partial);
            throw new \RuntimeException('The data package cannot be written.');
        }

        return (int) filesize($zipPath);
    }

    /**
     * @param string $workDir
     * @param string $file
     * @return \Generator<int, array<string, mixed>> Rows of one source.
     */
    public static function rows(string $workDir, string $file): \Generator
    {
        $path = $workDir . DIRECTORY_SEPARATOR . $file . '.ndjson';
        if (!is_file($path)) {
            return;
        }
        $handle = fopen($path, 'rb');
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    $row = json_decode($line, true);
                    if (is_array($row)) {
                        yield $row;
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string                                                                              $workDir Where the collected rows are.
     * @param string                                                                              $outDir
     * @param list<array{table: string, file: string, label: string, columns: list<string>, rows: int}> $parts
     * @param array<string, string>                                                               $texts
     * @return void
     */
    private function writeJson(string $workDir, string $outDir, array $parts, array $texts): void
    {
        $out = $this->open($outDir . DIRECTORY_SEPARATOR . 'data.json');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        fwrite($out, '{"generated_at":' . json_encode($texts['generated'], $flags) . ',"sources":[');
        foreach ($parts as $i => $part) {
            fwrite($out, ($i > 0 ? ',' : '') . '{"table":' . json_encode($part['table'], $flags) . ',"label":' . json_encode($part['label'], $flags) . ',"rows":[');
            $first = true;
            foreach (self::rows($workDir, $part['file']) as $row) {
                fwrite($out, ($first ? '' : ',') . json_encode($row, $flags));
                $first = false;
            }
            fwrite($out, ']}');
        }
        fwrite($out, ']}');
        fclose($out);
    }

    /**
     * @param string                                                                         $workDir
     * @param string                                                                         $csvDir
     * @param array{table: string, file: string, label: string, columns: list<string>, rows: int} $part
     * @return void
     */
    private function writeCsv(string $workDir, string $csvDir, array $part): void
    {
        $out = $this->open($csvDir . DIRECTORY_SEPARATOR . $part['file'] . '.csv');
        fwrite($out, "\xEF\xBB\xBF" . ExportFormat::csvLine($part['columns']));
        foreach (self::rows($workDir, $part['file']) as $row) {
            fwrite($out, ExportFormat::csvLine(array_map(fn (string $c) => $row[$c] ?? null, $part['columns'])));
        }
        fclose($out);
    }

    /**
     * @param string                                                                              $workDir Where the collected rows are.
     * @param string                                                                              $outDir
     * @param list<array{table: string, file: string, label: string, columns: list<string>, rows: int}> $parts
     * @param array<string, string>                                                               $texts
     * @return void
     */
    private function writeHtml(string $workDir, string $outDir, array $parts, array $texts): void
    {
        $e = fn (mixed $v): string => htmlspecialchars(is_array($v) || is_object($v) ? (string) json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = $this->open($outDir . DIRECTORY_SEPARATOR . 'index.html');
        fwrite($out, '<!doctype html><html lang="' . $e(app()->getLocale()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($texts['title']) . '</title><style>body{font-family:system-ui,sans-serif;margin:24px;color:#1f2937}h1{font-size:22px}h2{font-size:17px;margin-top:28px}'
            . 'table{border-collapse:collapse;width:100%;font-size:13px}th,td{border:1px solid #d1d5db;padding:6px;text-align:left;vertical-align:top;word-break:break-word}'
            . 'th{background:#f3f4f6}p.meta{color:#6b7280}</style></head><body>');
        fwrite($out, '<h1>' . $e($texts['title']) . '</h1><p>' . $e($texts['intro']) . '</p><p class="meta">' . $e($texts['generated']) . '</p>');
        foreach ($parts as $part) {
            fwrite($out, '<h2>' . $e($part['label']) . ' (' . (int) $part['rows'] . ')</h2>');
            if ((int) $part['rows'] === 0) {
                fwrite($out, '<p class="meta">' . $e($texts['empty']) . '</p>');
                continue;
            }
            fwrite($out, '<table><thead><tr>');
            foreach ($part['columns'] as $column) {
                fwrite($out, '<th>' . $e($column) . '</th>');
            }
            fwrite($out, '</tr></thead><tbody>');
            foreach (self::rows($workDir, $part['file']) as $row) {
                fwrite($out, '<tr>');
                foreach ($part['columns'] as $column) {
                    fwrite($out, '<td>' . $e($row[$column] ?? '') . '</td>');
                }
                fwrite($out, '</tr>');
            }
            fwrite($out, '</tbody></table>');
        }
        fwrite($out, '</body></html>');
        fclose($out);
    }

    /**
     * @param string $path
     * @return resource
     * @throws \RuntimeException
     */
    private function open(string $path)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('The data package cannot be written.');
        }

        return $handle;
    }
}
