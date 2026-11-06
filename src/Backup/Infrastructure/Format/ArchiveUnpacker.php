<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Domain\PackFormat;

/**
 * Splits a `.gp247bak` file back into an archive directory (PackFormat). Only the names an
 * archive may hold are accepted, each once and in order, with the size limits of the archive
 * reader; a missing end entry, a wrong entry count or hash, or trailing bytes reject the file and
 * leave nothing in the target directory. What is unpacked is still checked by ArchiveReader
 * (signature, hashes, AES-GCM) before any of it is trusted. One pass (tests, tools); the import
 * job goes through ResumableUnpacker, which shares the checks below.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-signed-download
 * @aidlc-adr backup-engine_archive-format
 */
final class ArchiveUnpacker
{
    private const CHUNK = 1_048_576;

    /** Most parts a packed archive may hold (16 MiB each ⇒ 1 TiB); bounds the work of a crafted file. */
    public const MAX_PARTS = 65_536;

    /**
     * @param string $file   Packed file.
     * @param string $target Directory to create (must not exist yet).
     * @return list<string> Names written.
     * @throws ArchiveRejected pack_truncated | pack_corrupt
     */
    public static function unpack(string $file, string $target): array
    {
        if (file_exists($target)) {
            throw new \InvalidArgumentException('Target directory already exists.');
        }
        $in = @fopen($file, 'rb');
        if ($in === false) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'not readable');
        }
        $written = [];
        try {
            if (!@mkdir($target, 0700, true)) {
                throw new \RuntimeException('Target directory not writable.');
            }
            $hash = hash_init('sha256');
            $magic = self::read($in, strlen(PackFormat::MAGIC) + 1, $hash);
            if ($magic !== PackFormat::MAGIC . chr(PackFormat::VERSION)) {
                throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'magic');
            }
            $expected = PackFormat::HEADER;
            $lastPart = null;
            while (true) {
                $type = ord(self::read($in, 1, null));
                if ($type === PackFormat::END) {
                    self::checkEnd($in, $hash, count($written), $expected);

                    return $written;
                }
                hash_update($hash, chr($type));
                $nameLength = unpack('n', self::read($in, 2, $hash))[1];
                $name = self::read($in, $nameLength, $hash);
                $size = unpack('J', self::read($in, 8, $hash))[1];
                self::checkEntry($type, $name, $size, $expected, $written, $lastPart);
                self::copy($in, $target . DIRECTORY_SEPARATOR . $name, $size, $hash);
                $written[] = $name;
                $lastPart = $type === PackFormat::PART ? $name : $lastPart;
                $expected = $type === PackFormat::HEADER ? PackFormat::MANIFEST : PackFormat::PART;
            }
        } catch (\Throwable $e) {
            self::clean($target);

            throw $e;
        } finally {
            fclose($in);
        }
    }

    /**
     * @param int          $type
     * @param string       $name
     * @param int          $size
     * @param int          $expected Type that must come next (parts may repeat).
     * @param list<string> $written
     * @param string|null  $lastPart Previous part name (parts come in ascending order).
     * @return void
     * @throws ArchiveRejected
     * @internal Shared with ResumableUnpacker so both accept exactly the same files.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function checkEntry(int $type, string $name, int $size, int $expected, array $written, ?string $lastPart): void
    {
        if ($type === PackFormat::PART && (int) substr($name, 5, 6) > self::MAX_PARTS) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'too many parts');
        }
        $limit = match ($type) {
            PackFormat::HEADER => ArchiveReader::MAX_HEADER_BYTES,
            PackFormat::MANIFEST => ArchiveReader::MAX_MANIFEST_BYTES,
            default => PHP_INT_MAX,
        };
        if ($type !== $expected || PackFormat::typeOf($name) !== $type || in_array($name, $written, true) || $size < 0 || $size > $limit
            || ($type === PackFormat::PART && $lastPart !== null && strcmp($name, $lastPart) <= 0)) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'entry ' . (PackFormat::typeOf($name) === null ? '?' : $name));
        }
    }

    /**
     * @param resource         $in
     * @param \HashContext     $hash
     * @param int              $count
     * @param int              $expected
     * @return void
     * @throws ArchiveRejected
     * @internal Shared with ResumableUnpacker.
     */
    public static function checkEnd($in, \HashContext $hash, int $count, int $expected): void
    {
        $nameLength = unpack('n', self::read($in, 2, null))[1];
        $size = unpack('J', self::read($in, 8, null))[1];
        if ($nameLength !== 0 || $size !== PackFormat::END_BYTES || $expected !== PackFormat::PART) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'end entry');
        }
        $body = self::read($in, PackFormat::END_BYTES, null);
        $declared = unpack('N', substr($body, 0, 4))[1];
        if ($declared !== $count || !hash_equals(hash_final($hash, true), substr($body, 4))) {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'end hash');
        }
        if (fread($in, 1) !== '') {
            throw new ArchiveRejected(ArchiveRejected::PACK_CORRUPT, 'trailing bytes');
        }
    }

    /**
     * @param resource          $in
     * @param int               $length
     * @param \HashContext|null $hash
     * @return string Exactly $length bytes.
     * @throws ArchiveRejected pack_truncated
     * @internal Shared with ResumableUnpacker.
     */
    public static function read($in, int $length, ?\HashContext $hash): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $chunk = fread($in, $length - strlen($out));
            if ($chunk === false || $chunk === '') {
                throw new ArchiveRejected(ArchiveRejected::PACK_TRUNCATED);
            }
            $out .= $chunk;
        }
        if ($hash !== null) {
            hash_update($hash, $out);
        }

        return $out;
    }

    /**
     * @param resource     $in
     * @param string       $path
     * @param int          $size
     * @param \HashContext $hash
     * @return void
     * @throws ArchiveRejected
     */
    private static function copy($in, string $path, int $size, \HashContext $hash): void
    {
        $out = fopen($path, 'xb');
        if ($out === false) {
            throw new \RuntimeException('Archive file not writable.');
        }
        try {
            $left = $size;
            while ($left > 0) {
                $chunk = self::read($in, min(self::CHUNK, $left), $hash);
                fwrite($out, $chunk);
                $left -= strlen($chunk);
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * @param string $target
     * @return void
     */
    private static function clean(string $target): void
    {
        foreach (scandir($target) ?: [] as $name) {
            if (PackFormat::typeOf($name) !== null && is_file($target . DIRECTORY_SEPARATOR . $name)) {
                @unlink($target . DIRECTORY_SEPARATOR . $name);
            }
        }
        @rmdir($target);
    }
}
