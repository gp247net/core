<?php

namespace GP247\Core\Backup\Domain;

/**
 * A destination that identifies its server by a host key (SFTP): the fingerprint it saw on the
 * last connection, so the administrator can compare and pin it.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters
 */
interface HostKeyAware
{
    /**
     * @return string|null "SHA256:<base64>" (as `ssh-keygen -l` prints it), null before connecting.
     */
    public function fingerprint(): ?string;
}
