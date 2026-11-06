<?php

namespace GP247\Core\Backup\Domain;

/**
 * The credential of the destination does not allow this operation (typically: delete with a
 * write-only credential — a valid configuration, not an error of the site).
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters
 */
final class NotPermitted extends DestinationFailure
{
    /**
     * @param string|null     $detail
     * @param \Throwable|null $previous
     */
    public function __construct(?string $detail = null, ?\Throwable $previous = null)
    {
        parent::__construct(self::NOT_PERMITTED, $detail, $previous);
    }
}
