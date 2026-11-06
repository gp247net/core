<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * An off-site action was refused before anything was sent: the destination is not set, the
 * archive is not ready, or the configuration does not pass the checks.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-credential-secret, US-backup-storage-fetch-remote
 * @aidlc-adr backup-storage_optional-adapters
 */
final class OffsiteRefused extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const NOT_READY = 'not_ready';
    public const ALREADY_RUNNING = 'already_running';
    public const NOT_FOUND = 'not_found';
    public const INCOMPLETE = 'incomplete';
    public const UNKNOWN_TYPE = 'unknown_type';
    public const UNAVAILABLE = 'unavailable';
    public const INVALID = 'invalid';

    /**
     * @param string       $reason   One of the constants.
     * @param list<string> $problems Field problems "<field>:<code>" (INVALID).
     */
    public function __construct(public readonly string $reason, public readonly array $problems = [])
    {
        parent::__construct($reason . ($problems === [] ? '' : ' (' . implode(', ', $problems) . ')'));
    }
}
