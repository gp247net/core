<?php

namespace GP247\Core\Backup\Domain;

/**
 * The single-file envelope `.gp247bak` around one archive directory (ADR archive-format,
 * amendment 2026-11-05 BK-4). It only carries files: secrecy and integrity come from the
 * archive itself (signed header, hashed manifest and parts, AES-GCM); the trailing "end" entry
 * lets a truncated download be rejected before anything is decrypted.
 *
 *   magic "GP247BAK" (8) ‖ version (1)
 *   entry: type (1) ‖ name length (u16 BE) ‖ name ‖ content length (u64 BE) ‖ content
 *   order: header → manifest → parts (ascending) → end
 *   end:   empty name, content = entry count before it (u32 BE) ‖ SHA-256 of every byte before the end entry
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-signed-download
 * @aidlc-adr backup-engine_archive-format
 */
final class PackFormat
{
    public const MAGIC = 'GP247BAK';
    public const VERSION = 1;

    public const HEADER = 1;
    public const MANIFEST = 2;
    public const PART = 3;
    public const END = 255;

    public const HEADER_NAME = 'header.json';
    public const MANIFEST_NAME = 'manifest.bin';
    public const PART_PATTERN = '/^part-\d{6}\.bin$/D';

    /** Bytes of the fixed part of an entry (type + name length + content length). */
    public const ENTRY_OVERHEAD = 1 + 2 + 8;

    /** Content of the end entry: count + SHA-256. */
    public const END_BYTES = 4 + 32;

    /**
     * @param string $name File name inside an archive directory.
     * @return int|null Entry type, or null when the name is not part of an archive.
     */
    public static function typeOf(string $name): ?int
    {
        return match (true) {
            $name === self::HEADER_NAME => self::HEADER,
            $name === self::MANIFEST_NAME => self::MANIFEST,
            preg_match(self::PART_PATTERN, $name) === 1 => self::PART,
            default => null,
        };
    }

    /**
     * @param int    $type
     * @param string $name
     * @param int    $length
     * @return string Bytes written before the content of an entry.
     */
    public static function entryHead(int $type, string $name, int $length): string
    {
        return chr($type) . pack('n', strlen($name)) . $name . pack('J', $length);
    }
}
