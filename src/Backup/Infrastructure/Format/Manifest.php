<?php

namespace GP247\Core\Backup\Infrastructure\Format;

use GP247\Core\Backup\Domain\ArchiveRejected;

/**
 * Strict check of a decrypted manifest (format 1) before anything in it is used: names,
 * part numbering, segment positions inside their part, row counts.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-safe-format, US-backup-engine-integrity-manifest
 * @aidlc-adr backup-engine_archive-format
 */
final class Manifest
{
    public const FORMAT_MAJOR = 1;
    public const FORMAT_MINOR = 0;

    /**
     * @param array<string, mixed> $manifest  Decoded manifest.
     * @param string               $archiveId Archive id from the signed header.
     * @return array<string, mixed> The same manifest.
     * @throws ArchiveRejected
     */
    public static function validate(array $manifest, string $archiveId): array
    {
        $format = $manifest['format'] ?? null;
        self::check(is_array($format) && ($format['major'] ?? null) === self::FORMAT_MAJOR && is_int($format['minor'] ?? null), 'format');
        self::check(($manifest['archive_id'] ?? null) === $archiveId, 'archive id');
        self::check(is_string($manifest['created_at'] ?? null), 'created_at');
        self::check(in_array($manifest['compression'] ?? null, ['gzip', 'none'], true), 'compression');
        self::check(is_array($manifest['meta'] ?? null), 'meta');

        $frames = self::parts($manifest['parts'] ?? null);
        $tables = $manifest['tables'] ?? null;
        self::check(is_array($tables) && ($tables === [] || !array_is_list($tables)), 'tables');
        foreach ($tables as $name => $table) {
            if (!RowCodec::validName((string) $name)) {
                throw new ArchiveRejected(ArchiveRejected::NAME_INVALID, 'table');
            }
            self::table($table, $frames);
        }

        return $manifest;
    }

    /**
     * @param mixed $parts
     * @return array<int, int> Part index => frame count.
     */
    private static function parts(mixed $parts): array
    {
        self::check(is_array($parts) && array_is_list($parts), 'parts');
        $frames = [];
        foreach ($parts as $position => $part) {
            self::check(is_array($part) && ($part['index'] ?? null) === $position + 1, 'part index');
            self::check(is_int($part['frames'] ?? null) && $part['frames'] >= 1, 'part frames');
            self::check(is_int($part['bytes'] ?? null) && $part['bytes'] >= strlen(PartFile::MAGIC), 'part bytes');
            self::check(self::isHash($part['sha256'] ?? null), 'part hash');
            $frames[$part['index']] = $part['frames'];
        }

        return $frames;
    }

    /**
     * @param mixed           $table
     * @param array<int, int> $frames
     * @return void
     */
    private static function table(mixed $table, array $frames): void
    {
        self::check(is_array($table) && is_array($table['columns'] ?? null) && array_is_list($table['columns']) && $table['columns'] !== [], 'columns');
        foreach ($table['columns'] as $column) {
            if (!RowCodec::validName($column)) {
                throw new ArchiveRejected(ArchiveRejected::NAME_INVALID, 'column');
            }
        }
        self::check(count(array_unique($table['columns'])) === count($table['columns']), 'duplicate column');
        self::check(is_int($table['rows'] ?? null) && $table['rows'] >= 0, 'rows');
        self::check(is_array($table['segments'] ?? null) && array_is_list($table['segments']), 'segments');

        $rows = 0;
        foreach ($table['segments'] as $segment) {
            self::check(is_array($segment), 'segment');
            $part = $segment['part'] ?? null;
            self::check(is_int($part) && isset($frames[$part]), 'segment part');
            $first = $segment['first'] ?? null;
            $last = $segment['last'] ?? null;
            // The last frame of a part is the empty closing frame, never segment data.
            self::check(is_int($first) && is_int($last) && $first >= 0 && $first <= $last && $last < $frames[$part] - 1, 'segment position');
            self::check(is_int($segment['rows'] ?? null) && $segment['rows'] >= 1, 'segment rows');
            self::check(is_int($segment['raw'] ?? null) && $segment['raw'] >= 1 && $segment['raw'] <= RowCodec::MAX_RAW_BYTES, 'segment size');
            self::check(self::isHash($segment['sha256'] ?? null), 'segment hash');
            $rows += $segment['rows'];
        }
        self::check($rows === $table['rows'], 'row count');
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private static function isHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /**
     * @param bool   $condition
     * @param string $what
     * @return void
     */
    private static function check(bool $condition, string $what): void
    {
        if (!$condition) {
            throw new ArchiveRejected(ArchiveRejected::MANIFEST_INVALID, $what);
        }
    }
}
