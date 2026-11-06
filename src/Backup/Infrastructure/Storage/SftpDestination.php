<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\HostKeyAware;
use GP247\Core\Backup\Domain\NotPermitted;
use League\Flysystem\FilesystemOperator;

/**
 * SFTP destination (server, NAS) built by SftpFactory over a host-key-pinned connection. SFTP
 * status codes of the failed operation decide the reason: "permission denied" (a folder where the
 * account may create files but not remove them) is NotPermitted.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-sftp, US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters
 */
final class SftpDestination extends FlysystemDestination implements HostKeyAware
{
    /**
     * @param FilesystemOperator   $filesystem
     * @param PinnedSftpConnection $connection
     */
    public function __construct(FilesystemOperator $filesystem, private readonly PinnedSftpConnection $connection)
    {
        parent::__construct($filesystem);
    }

    /**
     * {@inheritDoc}
     */
    public function type(): string
    {
        return SftpFactory::KEY;
    }

    /**
     * {@inheritDoc}
     */
    public function fingerprint(): ?string
    {
        return $this->connection->fingerprint();
    }

    /**
     * {@inheritDoc}
     */
    protected function classify(\Throwable $error, string $operation): DestinationFailure
    {
        $own = self::find($error, DestinationFailure::class);
        if ($own instanceof DestinationFailure) {
            return $own;
        }
        $status = strtoupper($this->connection->lastError());

        return match (true) {
            str_contains($status, 'PERMISSION_DENIED') || str_contains($status, 'WRITE_PROTECT') => new NotPermitted($operation, $error),
            str_contains($status, 'NO_SUCH_FILE') || str_contains($status, 'NO_SUCH_PATH') => new DestinationFailure(DestinationFailure::NOT_FOUND, $operation, $error),
            str_contains($status, 'NO_SPACE') || str_contains($status, 'QUOTA') => new DestinationFailure(DestinationFailure::QUOTA, $operation, $error),
            str_contains($status, 'CONNECTION_LOST') || str_contains($status, 'NO_CONNECTION') || $status === '' => new DestinationFailure(DestinationFailure::NETWORK, $operation, $error),
            default => new DestinationFailure(DestinationFailure::ERROR, $operation, $error),
        };
    }
}
