<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * A backup archive failed a format, signature or integrity check and must not be used.
 *
 * Carries a stable reason code (admin screen / CLI translate it later) and never any data
 * read from the archive.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-integrity-manifest, US-backup-engine-safe-format
 * @aidlc-adr backup-engine_tamper-evidence
 */
final class ArchiveRejected extends RuntimeException
{
    public const HEADER_INVALID = 'header_invalid';
    public const FORMAT_UNSUPPORTED = 'format_unsupported';
    public const SIGNATURE_UNAVAILABLE = 'signature_unavailable';
    public const SIGNATURE_MISMATCH = 'signature_mismatch';
    public const MANIFEST_HASH_MISMATCH = 'manifest_hash_mismatch';
    public const KEY_MISMATCH = 'key_mismatch';
    public const MANIFEST_INVALID = 'manifest_invalid';
    public const PART_MISSING = 'part_missing';
    public const PART_HASH_MISMATCH = 'part_hash_mismatch';
    public const FRAME_AUTH_FAILED = 'frame_auth_failed';
    public const TRUNCATED = 'truncated';
    public const SEGMENT_HASH_MISMATCH = 'segment_hash_mismatch';
    public const SEGMENT_INVALID = 'segment_invalid';
    public const NAME_INVALID = 'name_invalid';
    public const TABLE_UNKNOWN = 'table_unknown';
    /** A file path in a file group could leave its root (checked before anything is written). */
    public const PATH_INVALID = 'path_invalid';
    /** Single-file envelope (.gp247bak, ADR archive-format amendment BK-4): cut short / not a valid envelope. */
    public const PACK_TRUNCATED = 'pack_truncated';
    public const PACK_CORRUPT = 'pack_corrupt';

    /**
     * @param string $reason One of the class constants.
     * @param string $detail Technical detail without archive content (part / frame index…).
     */
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct('Backup archive rejected: ' . $reason . ($detail !== '' ? ' (' . $detail . ')' : ''));
    }
}
