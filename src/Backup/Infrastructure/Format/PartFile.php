<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use Generator;
use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Infrastructure\Crypto\FrameCipher;
use GP247\Core\Backup\Infrastructure\Crypto\OpenSsl;
use RuntimeException;

/**
 * One archive file (part-NNNNNN.bin, or manifest.bin as part 0): the magic "GP247BK\x01"
 * followed by frames `flags(1) ‖ length(4, big-endian) ‖ nonce(12) ‖ ciphertext ‖ tag(16)`. The last frame
 * is always an empty frame flagged final, so a cut file is detected even at a frame boundary.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-safe-format, US-backup-engine-integrity-manifest
 * @aidlc-adr backup-engine_archive-format, backup-engine_tamper-evidence
 */
final class PartFile
{
    public const MAGIC = "GP247BK\x01";
    private const FRAME_HEAD = 5;

    /**
     * @param string $path New file path (must not exist).
     * @return int Bytes written.
     */
    public static function create(string $path): int
    {
        if (file_exists($path)) {
            throw new RuntimeException('Archive file already exists: ' . basename($path));
        }
        if (file_put_contents($path, self::MAGIC, LOCK_EX) !== strlen(self::MAGIC)) {
            throw new RuntimeException('Cannot write archive file: ' . basename($path));
        }

        return strlen(self::MAGIC);
    }

    /**
     * @param string      $path      File path.
     * @param FrameCipher $cipher    Archive frame cipher.
     * @param int         $part      Part index.
     * @param int         $frame     Frame index.
     * @param bool        $final     Closing frame.
     * @param string      $plaintext ≤ 1 MiB.
     * @return int Bytes appended.
     */
    public static function append(string $path, FrameCipher $cipher, int $part, int $frame, bool $final, string $plaintext): int
    {
        $sealed = $cipher->seal($part, $frame, $final, $plaintext);
        $data = ($final ? "\x01" : "\x00") . pack('N', strlen($sealed)) . $sealed;
        if (file_put_contents($path, $data, FILE_APPEND | LOCK_EX) !== strlen($data)) {
            throw new RuntimeException('Cannot write archive file: ' . basename($path));
        }

        return strlen($data);
    }

    /**
     * Read and authenticate frames. Without a range the whole file is checked (frame count,
     * closing frame, nothing after it).
     *
     * @param string      $path   File path.
     * @param FrameCipher $cipher Archive frame cipher.
     * @param int         $part   Part index.
     * @param int         $frames Frame count recorded in the manifest / header.
     * @param int|null    $first  First frame to return.
     * @param int|null    $last   Last frame to return.
     * @return Generator<int, string> Frame index => plaintext.
     * @throws ArchiveRejected
     */
    public static function read(string $path, FrameCipher $cipher, int $part, int $frames, ?int $first = null, ?int $last = null): Generator
    {
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new ArchiveRejected(ArchiveRejected::PART_MISSING, 'part ' . $part);
        }
        $first ??= 0;
        $last ??= $frames - 1;
        $whole = $first === 0 && $last === $frames - 1;

        try {
            if (fread($handle, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'part ' . $part . ' magic');
            }
            for ($i = 0; $i < $frames; $i++) {
                [$final, $length] = self::frameHead($handle, $part);
                if ($i < $first) {
                    self::skip($handle, $length, $part);
                } else {
                    $sealed = self::readExactly($handle, $length, $part);
                    $plain = $cipher->open($part, $i, $final, $sealed);
                    if ($plain === null) {
                        throw new ArchiveRejected(ArchiveRejected::FRAME_AUTH_FAILED, 'part ' . $part . ' frame ' . $i);
                    }
                }
                if ($final !== ($i === $frames - 1)) {
                    throw new ArchiveRejected(ArchiveRejected::TRUNCATED, 'part ' . $part . ' frame ' . $i);
                }
                if ($i >= $first) {
                    yield $i => $plain;
                }
                if ($i >= $last && !$whole) {
                    return;
                }
            }
            if (fread($handle, 1) !== '') {
                throw new ArchiveRejected(ArchiveRejected::TRUNCATED, 'part ' . $part . ' trailing data');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param int      $part
     * @return array{0: bool, 1: int} Final flag, sealed length.
     */
    private static function frameHead($handle, int $part): array
    {
        $head = fread($handle, self::FRAME_HEAD);
        if (!is_string($head) || strlen($head) !== self::FRAME_HEAD) {
            throw new ArchiveRejected(ArchiveRejected::TRUNCATED, 'part ' . $part);
        }
        $flags = ord($head[0]);
        $length = unpack('N', substr($head, 1))[1];
        if ($flags > 1 || $length < FrameCipher::OVERHEAD || $length > FrameCipher::MAX_PLAINTEXT + FrameCipher::OVERHEAD) {
            throw new ArchiveRejected(ArchiveRejected::SEGMENT_INVALID, 'part ' . $part . ' frame header');
        }

        return [$flags === 1, $length];
    }

    /**
     * @param resource $handle
     * @param int      $length
     * @param int      $part
     * @return string
     */
    private static function readExactly($handle, int $length, int $part): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        if (strlen($data) !== $length) {
            throw new ArchiveRejected(ArchiveRejected::TRUNCATED, 'part ' . $part);
        }

        return $data;
    }

    /**
     * @param resource $handle
     * @param int      $length
     * @param int      $part
     * @return void
     */
    private static function skip($handle, int $length, int $part): void
    {
        $position = ftell($handle);
        $size = fstat($handle)['size'] ?? 0;
        if ($position === false || $position + $length > $size || fseek($handle, $length, SEEK_CUR) !== 0) {
            throw new ArchiveRejected(ArchiveRejected::TRUNCATED, 'part ' . $part);
        }
    }
}
