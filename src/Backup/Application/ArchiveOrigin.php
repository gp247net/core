<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\ArchiveRejected;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;

/**
 * How an archive's origin can be checked on this site (ADR backup-engine_tamper-evidence,
 * amendment BK-5). One rule for restore, re-check and export:
 *
 *  - catalogue row "unverified" (brought in, signed elsewhere) ⇒ unverified;
 *  - this site's signing key unreadable (lost .env / encryption key) ⇒ unverified;
 *  - signature does not match on an archive of this site ⇒ refused (the file was changed),
 *    never downgraded.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-import-archive, US-backup-restore-cli-restore
 * @aidlc-adr backup-engine_tamper-evidence
 */
final class ArchiveOrigin
{
    /**
     * @param object     $archive Catalogue row.
     * @param string     $dir     Archive folder.
     * @param KeyService $keys
     * @return array{origin: string, reason: string|null} reason: imported | signing_key_unavailable | null.
     * @throws ArchiveRejected signature_mismatch
     *
     * @aidlc-story US-backup-restore-import-archive, US-backup-restore-cli-restore
     */
    public static function of(object $archive, string $dir, KeyService $keys): array
    {
        if (self::normalise((string) ($archive->origin ?? '')) === ArchiveReader::ORIGIN_UNVERIFIED) {
            return ['origin' => ArchiveReader::ORIGIN_UNVERIFIED, 'reason' => 'imported'];
        }
        $signingKey = self::signingKey($keys);
        if ($signingKey === null) {
            return ['origin' => ArchiveReader::ORIGIN_UNVERIFIED, 'reason' => 'signing_key_unavailable'];
        }
        if (ArchiveReader::originOf($dir, $signingKey) !== ArchiveReader::ORIGIN_SITE) {
            throw new ArchiveRejected(ArchiveRejected::SIGNATURE_MISMATCH);
        }

        return ['origin' => ArchiveReader::ORIGIN_SITE, 'reason' => null];
    }

    /**
     * @param string $value Stored origin (catalogue column, job cursor).
     * @return string ArchiveReader::ORIGIN_UNVERIFIED for exactly that value, ORIGIN_SITE otherwise.
     *
     * @aidlc-story US-backup-restore-import-archive
     */
    public static function normalise(string $value): string
    {
        return $value === ArchiveReader::ORIGIN_UNVERIFIED ? ArchiveReader::ORIGIN_UNVERIFIED : ArchiveReader::ORIGIN_SITE;
    }

    /**
     * @param KeyService $keys
     * @return string|null The site signing key, null when it cannot be read (damaged configuration).
     *
     * @aidlc-story US-backup-restore-cli-restore
     */
    public static function signingKey(KeyService $keys): ?string
    {
        try {
            return $keys->signingKey();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
