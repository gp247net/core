<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * A restore that cannot be previewed, started or undone. The reason code is stable so the admin
 * screen and the CLI can explain it in the user's language (BK-4 / BK-5); the message is for logs.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database, US-backup-restore-version-compatibility, US-backup-restore-cli-restore, US-backup-restore-import-archive, US-backup-restore-post-incident-mode
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class RestoreRefused extends RuntimeException
{
    public const FEATURE_DISABLED = 'feature_disabled';
    public const ARCHIVE_NOT_READY = 'archive_not_ready';
    public const ARCHIVE_KIND = 'archive_kind';
    public const LEVEL_INVALID = 'level_invalid';
    public const GROUP_UNKNOWN = 'group_unknown';
    public const SCOPE_EMPTY = 'scope_empty';
    public const NOTHING_TO_RESTORE = 'nothing_to_restore';
    public const FK_OUTSIDE_SCOPE = 'fk_outside_scope';
    public const FK_NOT_SUPPORTED = 'fk_not_supported';
    public const ARCHIVE_NEWER = 'archive_newer';
    public const ALREADY_RUNNING = 'already_running';
    public const NO_KEY = 'no_key';
    public const TOO_MANY_ROWS = 'too_many_rows';
    public const NOTHING_TO_UNDO = 'nothing_to_undo';
    public const UNDO_EXPIRED = 'undo_expired';
    public const IN_TRANSACTION = 'restore_in_transaction';
    public const DRIVER_UNSUPPORTED = 'driver_unsupported';
    public const NOT_FOUND = 'not_found';
    public const NOT_CANCELLABLE = 'not_cancellable';
    // Bolt BK-5: archives brought in, files, post-incident mode, CLI.
    public const ORIGIN_UNVERIFIED = 'origin_unverified';
    public const FILES_NOT_ALLOWED = 'files_not_allowed';
    public const FILE_GROUP_UNKNOWN = 'file_group_unknown';
    public const INTERACTIVE_ONLY = 'interactive_only';
    public const ALREADY_IN_CATALOGUE = 'already_in_catalogue';
    public const POST_INCIDENT_LEVEL = 'post_incident_level';
    public const UPLOAD_TOO_LARGE = 'upload_too_large';
    public const UPLOAD_OFFSET = 'upload_offset';
    public const UPLOAD_UNKNOWN = 'upload_unknown';
    public const DISK_SPACE = 'disk_space';
    public const CONFIRMATION_REQUIRED = 'confirmation_required';

    /**
     * @param string               $reason  One of the constants.
     * @param string               $detail  Short technical detail (no secrets, no row values).
     * @param array<string, mixed> $context Data for the caller (tables, migrations…).
     */
    public function __construct(public readonly string $reason, string $detail = '', public readonly array $context = [])
    {
        parent::__construct($reason . ($detail !== '' ? ': ' . $detail : ''));
    }
}
