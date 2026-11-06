<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\PackFormat;

/**
 * Splits a `.gp247bak` file back into an archive directory over several requests (shared hosts
 * cannot copy 500 MB inside one request). Accepts exactly what ArchiveUnpacker accepts — its
 * entry and end checks are shared — and keeps its position, the running hash (a serialised
 * HashContext) and the entry being copied in a small state array the caller stores between
 * slices (parts are counted, not listed: their ascending names already make them unique).
 *
 * A slice that died after writing bytes but before its state was saved is detected on resume:
 * the file being copied is cut back to the length the saved state expects. Only a file the
 * checks refuse removes what was unpacked; any other error (a lost lease, a passing I/O error)
 * leaves it for the next attempt.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive
 * @aidlc-adr backup-engine_archive-format
 */
final class ResumableUnpacker
{
    private const CHUNK = 1_048_576;

    /**
     * Unpack as much as $continue allows.
     *
     * @param string                $file     Packed file (complete).
     * @param string                $target   Archive directory (created on the first call; must not exist then).
     * @param array<string, mixed>  $state    [] on the first call, then what the previous call returned.
     * @param callable(array): bool $continue Asked between chunks with the current state; false = stop.
     * @return array<string, mixed> New state; "done" => true once the end entry checked out.
     * @throws ArchiveRejected pack_truncated | pack_corrupt (the target directory is removed)
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function step(string $file, string $target, array $state, callable $continue): array
    {
        $in = @fopen($file, 'rb');
        if ($in === false) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'not readable');
        }
        try {
            if ($state === []) {
                $state = self::begin($in, $target);
            } elseif (!is_dir($target) || fseek($in, (int) $state['pos']) !== 0) {
                throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'resume');
            }
            $hash = self::hashFrom($state);
            while (empty($state['done'])) {
                $state = $state['entry'] !== null ? self::copyChunk($in, $target, $state, $hash) : self::nextEntry($in, $target, $state, $hash);
                $state['pos'] = ftell($in);
                $state['hash'] = base64_encode(serialize($hash));
                if (empty($state['done']) && !$continue($state)) {
                    break;
                }
            }

            return $state;
        } catch (ArchiveRejected $e) {
            self::clean($target);

            throw $e;
        } finally {
            fclose($in);
        }
    }

    /**
     * Remove what an unpack wrote (only archive file names, then the directory).
     *
     * @param string $target
     * @return void
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function clean(string $target): void
    {
        if (!is_dir($target) || is_link($target)) {
            return;
        }
        foreach (scandir($target) ?: [] as $name) {
            $path = $target . DIRECTORY_SEPARATOR . $name;
            if (PackFormat::typeOf($name) !== null && is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($target);
    }

    /**
     * @param resource $in
     * @param string   $target
     * @return array<string, mixed>
     * @throws ArchiveRejected
     */
    private static function begin($in, string $target): array
    {
        if (file_exists($target)) {
            throw new \InvalidArgumentException('Target directory already exists.');
        }
        if (!@mkdir($target, 0700, true)) {
            throw new \RuntimeException('Target directory not writable.');
        }
        $hash = hash_init('sha256');
        if (ArchiveUnpacker::read($in, strlen(PackFormat::MAGIC) + 1, $hash) !== PackFormat::MAGIC . chr(PackFormat::VERSION)) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'magic');
        }

        return [
            'pos' => ftell($in),
            'hash' => base64_encode(serialize($hash)),
            'expected' => PackFormat::HEADER,
            'last_part' => null,
            'written' => [],
            'parts' => 0,
            'entry' => null,
            'done' => false,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return \HashContext
     * @throws ArchiveRejected
     */
    private static function hashFrom(array $state): \HashContext
    {
        $raw = base64_decode((string) ($state['hash'] ?? ''), true);
        // WHY allowed_classes: only a HashContext may come back from the stored state.
        $hash = $raw === false ? false : @unserialize($raw, ['allowed_classes' => [\HashContext::class]]);
        if (!$hash instanceof \HashContext) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'state');
        }

        return $hash;
    }

    /**
     * @param resource             $in
     * @param string               $target
     * @param array<string, mixed> $state
     * @param \HashContext         $hash
     * @return array<string, mixed>
     * @throws ArchiveRejected
     */
    private static function nextEntry($in, string $target, array $state, \HashContext $hash): array
    {
        $type = ord(ArchiveUnpacker::read($in, 1, null));
        if ($type === PackFormat::END) {
            ArchiveUnpacker::checkEnd($in, hash_copy($hash), count($state['written']) + (int) $state['parts'], (int) $state['expected']);
            $state['done'] = true;

            return $state;
        }
        hash_update($hash, chr($type));
        $nameLength = unpack('n', ArchiveUnpacker::read($in, 2, $hash))[1];
        $name = ArchiveUnpacker::read($in, $nameLength, $hash);
        $size = unpack('J', ArchiveUnpacker::read($in, 8, $hash))[1];
        ArchiveUnpacker::checkEntry($type, $name, $size, (int) $state['expected'], (array) $state['written'], $state['last_part']);
        $path = $target . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'link');
        }
        // WHY "w" and not "x": a slice that died after this point already created the file;
        // the entry was checked above (an archive name, not written yet), so it starts afresh.
        $out = @fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Archive file not writable.');
        }
        fclose($out);
        $state['entry'] = ['name' => $name, 'type' => $type, 'size' => $size, 'left' => $size];

        return $state['entry']['left'] === 0 ? self::endEntry($state) : $state;
    }

    /**
     * @param resource             $in
     * @param string               $target
     * @param array<string, mixed> $state
     * @param \HashContext         $hash
     * @return array<string, mixed>
     * @throws ArchiveRejected
     */
    private static function copyChunk($in, string $target, array $state, \HashContext $hash): array
    {
        $entry = $state['entry'];
        $path = $target . DIRECTORY_SEPARATOR . $entry['name'];
        if (PackFormat::typeOf($entry['name']) === null || !is_file($path) || is_link($path)) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'resume entry');
        }
        $out = fopen($path, 'r+b');
        try {
            // A previous slice may have written past the saved state before dying.
            ftruncate($out, $entry['size'] - $entry['left']);
            fseek($out, 0, SEEK_END);
            $chunk = ArchiveUnpacker::read($in, min(self::CHUNK, $entry['left']), $hash);
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw new \RuntimeException('Archive file write failed.');
            }
        } finally {
            fclose($out);
        }
        $state['entry']['left'] -= strlen($chunk);

        return $state['entry']['left'] === 0 ? self::endEntry($state) : $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function endEntry(array $state): array
    {
        $entry = $state['entry'];
        if ($entry['type'] === PackFormat::PART) {
            $state['parts'] = (int) $state['parts'] + 1;
            $state['last_part'] = $entry['name'];
        } else {
            $state['written'][] = $entry['name'];
        }
        $state['expected'] = $entry['type'] === PackFormat::HEADER ? PackFormat::MANIFEST : PackFormat::PART;
        $state['entry'] = null;

        return $state;
    }
}
