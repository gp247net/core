<?php

namespace GP247\Core\Backup\Domain;

/**
 * Where archives live in an external destination:
 *   <configured folder>/<site key>/<archive id>/{part-NNNNNN.bin, manifest.bin, header.json}
 * The site key is a short hash of APP_URL, so several sites can share one bucket. header.json is
 * written last: an archive folder without it is unfinished. Names read back from a destination are
 * accepted only when they match this layout.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-fetch-remote
 * @aidlc-adr backup-storage_optional-adapters
 */
final class RemoteLayout
{
    public const HEADER = 'header.json';
    public const MANIFEST = 'manifest.bin';
    /** File that "Test the connection" writes (one fixed name: re-written, never piling up). */
    public const PROBE = '.gp247-check';

    /**
     * @param string|null $appUrl Null = config('app.url').
     * @return string 16 hex characters.
     */
    public static function siteKey(?string $appUrl = null): string
    {
        $url = strtolower(rtrim(trim((string) ($appUrl ?? config('app.url'))), '/'));
        $url = (string) preg_replace('#^https?://#', '', $url);

        return substr(hash('sha256', $url), 0, 16);
    }

    /**
     * @param string $siteKey
     * @param string $archiveId
     * @param string $name
     * @return string
     */
    public static function path(string $siteKey, string $archiveId, string $name): string
    {
        if (!self::isArchiveId($archiveId) || !self::isFileName($name)) {
            throw new \InvalidArgumentException('Not a remote archive path.');
        }

        return $siteKey . '/' . $archiveId . '/' . $name;
    }

    /**
     * @param string $id
     * @return bool
     */
    public static function isArchiveId(string $id): bool
    {
        return preg_match('/^[0-9A-Z]{26}$/D', $id) === 1;
    }

    /**
     * @param string $name
     * @return bool A file of an archive folder.
     */
    public static function isFileName(string $name): bool
    {
        return $name === self::HEADER || $name === self::MANIFEST || preg_match('/^part-\d{6}\.bin$/D', $name) === 1;
    }

    /**
     * Order in which an archive is copied: parts, then the manifest, then the header — so a
     * destination that has the header has everything.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public static function uploadOrder(array $names): array
    {
        $parts = array_values(array_filter($names, fn ($n) => preg_match('/^part-\d{6}\.bin$/D', $n) === 1));
        sort($parts);
        $rest = array_values(array_intersect([self::MANIFEST, self::HEADER], $names));

        return array_merge($parts, $rest);
    }

    /**
     * @param string $siteKey
     * @param string $path Path relative to the destination folder.
     * @return array{0: string, 1: string}|null [archive id, file name] when the path is part of this site's layout.
     */
    public static function parse(string $siteKey, string $path): ?array
    {
        $segments = explode('/', trim(str_replace('\\', '/', $path), '/'));
        if (count($segments) !== 3 || $segments[0] !== $siteKey || !self::isArchiveId($segments[1]) || !self::isFileName($segments[2])) {
            return null;
        }

        return [$segments[1], $segments[2]];
    }
}
