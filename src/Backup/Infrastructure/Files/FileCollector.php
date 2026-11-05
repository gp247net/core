<?php

namespace GP247\Core\Backup\Infrastructure\Files;

use Generator;
use GP247\Core\Backup\Domain\FileGroup;

/**
 * Walks one file group depth-first, every directory sorted by name, and hands out chunks, so a
 * backup can stop after any chunk and resume from (path, offset) in a later request.
 *
 * The walk is lazy: memory holds one directory listing per level, and resuming prunes every
 * directory that lies wholly before the saved path — a large uploads tree is not listed again
 * on every request. Order is "component-wise": paths compare folder name by folder name, which
 * is exactly the walk order.
 *
 * Skipped (with a warning, never silently): excluded directory names at any depth, the backup
 * vault itself (a vault inside a backed-up folder would copy archives into archives), symbolic
 * links that resolve outside the group root, unreadable files, and files that shrank since a
 * previous request read part of them.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-full-backup, US-backup-engine-selective-backup, US-backup-engine-resumable-run
 * @aidlc-adr backup-engine_archive-format
 */
final class FileCollector
{
    /** One chunk = at most one frame of plaintext before encoding. */
    public const CHUNK_BYTES = 1_048_576;

    /** @var list<string> */
    private array $warnings = [];

    private ?string $skipDir;

    /**
     * @param FileGroup   $group
     * @param int         $chunkBytes Chunk size (tests use small values).
     * @param string|null $skipDir    Directory never walked (the backup vault).
     */
    public function __construct(private readonly FileGroup $group, private readonly int $chunkBytes = self::CHUNK_BYTES, ?string $skipDir = null)
    {
        $this->skipDir = $skipDir === null ? null : self::normalise(realpath($skipDir) ?: $skipDir);
    }

    /**
     * @return list<string> Relative paths ("/" separated) in walk order (tests, diagnostics).
     */
    public function files(): array
    {
        return iterator_to_array($this->walk(null), false);
    }

    /**
     * @return array{count: int, bytes: int}
     */
    public function totals(): array
    {
        $count = 0;
        $bytes = 0;
        foreach ($this->walk(null) as $relative) {
            $count++;
            $bytes += (int) @filesize($this->absolute($relative));
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    /**
     * Chunks from a position. An empty file yields one empty chunk so it is restored too.
     *
     * @param string|null $path   File to continue (null = from the first file).
     * @param int         $offset Next byte of $path; negative = $path is complete, go on after it.
     * @return Generator<int, array{path: string, offset: int, size: int, mtime: int, data: string}>
     */
    public function chunks(?string $path, int $offset): Generator
    {
        foreach ($this->walk($path) as $relative) {
            if ($path !== null && $relative === $path) {
                if ($offset < 0) {
                    continue;
                }
                yield from $this->fileChunks($relative, $offset);
                continue;
            }
            yield from $this->fileChunks($relative, 0);
        }
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_unique($this->warnings));
    }

    /**
     * Compare two relative paths in walk order (folder name by folder name).
     *
     * @param string $a
     * @param string $b
     * @return int <0, 0, >0
     */
    public static function compare(string $a, string $b): int
    {
        $left = explode('/', $a);
        $right = explode('/', $b);
        $n = min(count($left), count($right));
        for ($i = 0; $i < $n; $i++) {
            $c = strcmp($left[$i], $right[$i]);
            if ($c !== 0) {
                return $c;
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * Files at or after $from in walk order.
     *
     * @param string|null $from
     * @return Generator<int, string>
     */
    private function walk(?string $from): Generator
    {
        if ($this->group->missing()) {
            return;
        }
        if ($this->group->isFile()) {
            yield basename($this->group->path);

            return;
        }
        $root = rtrim($this->group->path, '/\\');
        $realRoot = self::normalise(realpath($root) ?: $root);
        yield from $this->walkDirectory($root, '', $realRoot, $from);
    }

    /**
     * @param string      $absolute Directory path.
     * @param string      $relative Its path relative to the group root ('' for the root).
     * @param string      $realRoot Normalised real path of the group root.
     * @param string|null $from     Resume position.
     * @return Generator<int, string>
     */
    private function walkDirectory(string $absolute, string $relative, string $realRoot, ?string $from): Generator
    {
        $entries = @scandir($absolute);
        if ($entries === false) {
            $this->warnings[] = 'unreadable:' . $this->group->key . '/' . $relative;

            return;
        }
        sort($entries, SORT_STRING);
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $absolute . DIRECTORY_SEPARATOR . $name;
            $rel = $relative === '' ? $name : $relative . '/' . $name;
            if (is_link($path) || self::isJunction($path)) {
                $target = realpath($path);
                if ($target === false || !is_file($target) || !self::inside(self::normalise($target), $realRoot)) {
                    $this->warnings[] = 'link_skipped:' . $this->group->key . '/' . $rel;
                    continue;
                }
            }
            if (is_dir($path)) {
                if (in_array($name, $this->group->exclude, true)) {
                    continue;
                }
                if ($this->skipDir !== null && self::normalise(realpath($path) ?: $path) === $this->skipDir) {
                    $this->warnings[] = 'vault_skipped:' . $this->group->key . '/' . $rel;
                    continue;
                }
                // Prune: the whole folder lies before the resume position.
                if ($from !== null && self::compare($rel, $from) < 0 && !str_starts_with($from, $rel . '/')) {
                    continue;
                }
                yield from $this->walkDirectory($path, $rel, $realRoot, $from);
                continue;
            }
            if (is_file($path) && ($from === null || self::compare($rel, $from) >= 0)) {
                yield $rel;
            }
        }
    }

    /**
     * @param string $relative
     * @param int    $start
     * @return Generator<int, array{path: string, offset: int, size: int, mtime: int, data: string}>
     */
    private function fileChunks(string $relative, int $start): Generator
    {
        $handle = @fopen($this->absolute($relative), 'rb');
        if ($handle === false) {
            $this->warnings[] = 'unreadable:' . $this->group->key . '/' . $relative;

            return;
        }
        try {
            $stat = fstat($handle) ?: [];
            $size = (int) ($stat['size'] ?? 0);
            $mtime = (int) ($stat['mtime'] ?? 0);
            if ($start > $size) {
                // Shrank since a previous request read part of it: what was stored is stale.
                $this->warnings[] = 'changed:' . $this->group->key . '/' . $relative;

                return;
            }
            if ($size === 0 && $start === 0) {
                yield ['path' => $relative, 'offset' => 0, 'size' => 0, 'mtime' => $mtime, 'data' => ''];

                return;
            }
            if ($start > 0 && fseek($handle, $start) !== 0) {
                return;
            }
            $position = $start;
            while (!feof($handle)) {
                $data = fread($handle, $this->chunkBytes);
                if ($data === false || $data === '') {
                    break;
                }
                yield ['path' => $relative, 'offset' => $position, 'size' => $size, 'mtime' => $mtime, 'data' => $data];
                $position += strlen($data);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param string $relative
     * @return string
     */
    private function absolute(string $relative): string
    {
        return $this->group->isFile() ? $this->group->path : rtrim($this->group->path, '/\\') . '/' . $relative;
    }

    /**
     * @param string $path
     * @return bool A Windows junction (not reported by is_link()).
     */
    private static function isJunction(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || !is_dir($path)) {
            return false;
        }
        // WHY: is_link() does not see junctions; a junction resolves somewhere other than
        // "real parent + its own name" (comparing with the parent keeps links above the root harmless).
        $real = realpath($path);
        $parent = realpath(dirname($path));

        return $real !== false && $parent !== false
            && self::normalise($real) !== self::normalise($parent . '/' . basename($path));
    }

    /**
     * @param string $path
     * @return string Forward slashes, no trailing slash, lower case on Windows.
     */
    private static function normalise(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    /**
     * @param string $path Normalised resolved path.
     * @param string $root Normalised resolved root.
     * @return bool
     */
    private static function inside(string $path, string $root): bool
    {
        return str_starts_with($path, $root . '/');
    }
}
