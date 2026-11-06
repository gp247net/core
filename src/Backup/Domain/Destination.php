<?php

namespace GP247\Core\Backup\Domain;

/**
 * An external place archives are copied to (S3-compatible, SFTP; BackupPro adds more types
 * through gp247-config.backup.destinations). Only primitive file operations: what the
 * credential is allowed to do is found out by trying (ConnectionProbe), so a write-only
 * credential is a complete, valid configuration.
 *
 * Paths are relative to the configured folder of the destination, "/"-separated.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters
 */
interface Destination
{
    /**
     * @return string Type key (s3, sftp, …).
     */
    public function type(): string;

    /**
     * Write (or overwrite) a file from a stream.
     *
     * @param string   $path
     * @param resource $stream
     * @return void
     * @throws DestinationFailure
     */
    public function put(string $path, $stream): void;

    /**
     * @param string $path
     * @return resource Readable stream.
     * @throws DestinationFailure
     */
    public function read(string $path);

    /**
     * @param string $path
     * @return int|null Size in bytes, null when the file does not exist.
     * @throws DestinationFailure
     */
    public function size(string $path): ?int;

    /**
     * Files below a folder, at every depth.
     *
     * @param string $prefix
     * @return iterable<array{path: string, size: int, modified: int|null}>
     * @throws DestinationFailure
     */
    public function files(string $prefix): iterable;

    /**
     * @param string $path
     * @return void
     * @throws NotPermitted When the credential may not delete.
     * @throws DestinationFailure
     */
    public function delete(string $path): void;
}
