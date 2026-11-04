<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * A key set-up request was refused (already set up, password too short).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class BackupKeyError extends RuntimeException
{
    public const ALREADY_SET_UP = 'already_set_up';
    public const PASSWORD_TOO_SHORT = 'password_too_short';

    /**
     * @param string $reason One of the class constants.
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Backup key set-up refused: ' . $reason);
    }
}
