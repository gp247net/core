<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * No backup password / recovery key has been set up on this site yet, so no archive can be
 * created (every archive is encrypted — there is no plaintext mode).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-encryption, US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class BackupNotSetUp extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Backup encryption is not set up: set a backup password first.');
    }
}
