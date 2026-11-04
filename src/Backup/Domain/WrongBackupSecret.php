<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * The backup password or recovery key given does not open the site key.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-encryption, US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class WrongBackupSecret extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The backup password or recovery key is not correct.');
    }
}
