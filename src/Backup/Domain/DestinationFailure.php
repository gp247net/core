<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * An external destination could not do what was asked. The reason is a fixed code, never the
 * provider's message (it may quote signed URLs, keys or host details).
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters
 */
class DestinationFailure extends RuntimeException
{
    public const AUTH = 'auth';
    public const NOT_PERMITTED = 'not_permitted';
    public const NETWORK = 'network';
    public const HOST_KEY_CHANGED = 'host_key_changed';
    public const HOST_KEY_UNKNOWN = 'host_key_unknown';
    public const UNAVAILABLE = 'unavailable';
    public const MISMATCH = 'mismatch';
    public const QUOTA = 'quota';
    public const INVALID_CONFIG = 'invalid_config';
    public const NOT_CONFIGURED = 'not_configured';
    public const NOT_FOUND = 'not_found';
    public const ERROR = 'error';

    /** @var list<string> Reasons a retry will not cure: the job ends at once. */
    public const PERMANENT = [self::AUTH, self::NOT_PERMITTED, self::HOST_KEY_CHANGED, self::HOST_KEY_UNKNOWN, self::UNAVAILABLE, self::INVALID_CONFIG, self::NOT_CONFIGURED];

    /**
     * @param string          $reason   One of the constants.
     * @param string|null     $detail   Safe detail for logs (never a secret).
     * @param \Throwable|null $previous
     * @param string|null     $fingerprint Host key fingerprint seen (SFTP).
     */
    public function __construct(public readonly string $reason, ?string $detail = null, ?\Throwable $previous = null, public readonly ?string $fingerprint = null)
    {
        parent::__construct($reason . ($detail === null || $detail === '' ? '' : ': ' . mb_substr($detail, 0, 150)), 0, $previous);
    }

    /**
     * @return bool A retry will not cure it.
     */
    public function permanent(): bool
    {
        return in_array($this->reason, self::PERMANENT, true);
    }
}
