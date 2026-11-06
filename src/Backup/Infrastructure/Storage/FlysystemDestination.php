<?php

namespace GP247\Core\Backup\Infrastructure\Storage;

use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\DestinationFailure;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

/**
 * A destination on top of a Flysystem filesystem built at run time from the decrypted
 * configuration (no disk is added to config/filesystems.php). Every library error is turned into
 * a DestinationFailure with a fixed reason by the type (classify()); the provider's message never
 * leaves this class.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters
 */
abstract class FlysystemDestination implements Destination
{
    /**
     * @param FilesystemOperator $filesystem
     */
    public function __construct(protected readonly FilesystemOperator $filesystem)
    {
    }

    /**
     * @param \Throwable $error
     * @param string     $operation put | read | size | list | delete
     * @return DestinationFailure
     */
    abstract protected function classify(\Throwable $error, string $operation): DestinationFailure;

    /**
     * {@inheritDoc}
     */
    public function put(string $path, $stream): void
    {
        $this->guard(fn () => $this->filesystem->writeStream($path, $stream), 'put');
    }

    /**
     * {@inheritDoc}
     */
    public function read(string $path)
    {
        return $this->guard(fn () => $this->filesystem->readStream($path), 'read');
    }

    /**
     * {@inheritDoc}
     */
    public function size(string $path): ?int
    {
        return $this->guard(fn () => $this->filesystem->fileExists($path) ? $this->filesystem->fileSize($path) : null, 'size');
    }

    /**
     * {@inheritDoc}
     */
    public function files(string $prefix): iterable
    {
        try {
            /** @var StorageAttributes $item */
            foreach ($this->filesystem->listContents($prefix, true) as $item) {
                if ($item->isFile()) {
                    yield ['path' => $item->path(), 'size' => (int) $item->fileSize(), 'modified' => $item->lastModified()];
                }
            }
        } catch (DestinationFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->classify($e, 'list');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $path): void
    {
        $this->guard(fn () => $this->filesystem->delete($path), 'delete');
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @param string        $name
     * @return T
     * @throws DestinationFailure
     */
    protected function guard(callable $operation, string $name): mixed
    {
        try {
            return $operation();
        } catch (DestinationFailure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->classify($e, $name);
        }
    }

    /**
     * @param \Throwable $error
     * @param string     $class
     * @return \Throwable|null The first exception of that class in the chain.
     */
    protected static function find(\Throwable $error, string $class): ?\Throwable
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof $class) {
                return $e;
            }
        }

        return null;
    }
}
