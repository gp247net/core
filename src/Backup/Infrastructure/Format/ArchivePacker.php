<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\PackFormat;

/**
 * Streams one archive directory as a single `.gp247bak` file (PackFormat), reading 1 MiB at a
 * time so a large archive never sits in memory. The size is known before the first byte, so the
 * download can send Content-Length.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-signed-download
 * @aidlc-adr backup-engine_archive-format
 */
final class ArchivePacker
{
    private const CHUNK = 1_048_576;

    /** @var list<array{type: int, name: string, path: string, size: int}> */
    private array $entries;

    /**
     * @param string $dir Archive directory.
     * @throws ArchiveRejected When the directory is not a closed archive.
     */
    public function __construct(private readonly string $dir)
    {
        $this->entries = self::entries($dir);
    }

    /**
     * @return int Bytes of the packed file.
     */
    public function size(): int
    {
        $bytes = strlen(PackFormat::MAGIC) + 1;
        foreach ($this->entries as $entry) {
            $bytes += PackFormat::ENTRY_OVERHEAD + strlen($entry['name']) + $entry['size'];
        }

        return $bytes + PackFormat::ENTRY_OVERHEAD + PackFormat::END_BYTES;
    }

    /**
     * @param callable(string): void $write Receives the bytes in order.
     * @return void
     * @throws \RuntimeException When a file changes size or cannot be read while streaming.
     */
    public function stream(callable $write): void
    {
        $hash = hash_init('sha256');
        $emit = static function (string $bytes) use ($write, $hash): void {
            hash_update($hash, $bytes);
            $write($bytes);
        };
        $emit(PackFormat::MAGIC . chr(PackFormat::VERSION));
        foreach ($this->entries as $entry) {
            $emit(PackFormat::entryHead($entry['type'], $entry['name'], $entry['size']));
            $handle = @fopen($entry['path'], 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Archive file not readable: ' . $entry['name']);
            }
            try {
                $left = $entry['size'];
                while ($left > 0) {
                    $chunk = fread($handle, min(self::CHUNK, $left));
                    if ($chunk === false || $chunk === '') {
                        throw new \RuntimeException('Archive file shorter than expected: ' . $entry['name']);
                    }
                    $left -= strlen($chunk);
                    $emit($chunk);
                }
            } finally {
                fclose($handle);
            }
        }
        $digest = hash_final($hash, true);
        $write(PackFormat::entryHead(PackFormat::END, '', PackFormat::END_BYTES) . pack('N', count($this->entries)) . $digest);
    }

    /**
     * @param string $dir
     * @return list<array{type: int, name: string, path: string, size: int}> header, manifest, parts in order.
     * @throws ArchiveRejected
     */
    private static function entries(string $dir): array
    {
        $header = $dir . DIRECTORY_SEPARATOR . PackFormat::HEADER_NAME;
        $manifest = $dir . DIRECTORY_SEPARATOR . PackFormat::MANIFEST_NAME;
        if (!is_file($header) || is_link($header) || !is_file($manifest) || is_link($manifest)) {
            throw new ArchiveRejected(ArchiveRejected::HEADER_INVALID, 'header or manifest missing');
        }
        $parts = [];
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (PackFormat::typeOf($name) === PackFormat::PART && is_file($path) && !is_link($path)) {
                $parts[] = $name;
            }
        }
        sort($parts, SORT_STRING);
        $out = [];
        foreach (array_merge([PackFormat::HEADER_NAME, PackFormat::MANIFEST_NAME], $parts) as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            clearstatcache(true, $path);
            $out[] = ['type' => (int) PackFormat::typeOf($name), 'name' => $name, 'path' => $path, 'size' => (int) filesize($path)];
        }

        return $out;
    }
}
