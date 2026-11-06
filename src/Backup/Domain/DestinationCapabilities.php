<?php

namespace GP247\Core\Backup\Domain;

/**
 * What the credential of a destination was found to allow. Write + read + list is everything the
 * backup feature needs; delete is optional — without it, old copies are left to the
 * destination's own lifecycle rule (the "immutable 1" of 3-2-1-1-0).
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters
 */
final class DestinationCapabilities
{
    /**
     * @param bool $write
     * @param bool $read
     * @param bool $list
     * @param bool $delete
     */
    public function __construct(
        public readonly bool $write,
        public readonly bool $read,
        public readonly bool $list,
        public readonly bool $delete,
    ) {
    }

    /**
     * @return bool Archives can be copied out, listed and fetched back.
     */
    public function usable(): bool
    {
        return $this->write && $this->read && $this->list;
    }

    /**
     * @return bool Usable and unable to delete: the recommended set-up.
     */
    public function writeOnly(): bool
    {
        return $this->usable() && !$this->delete;
    }

    /**
     * @return list<string> Missing rights the feature needs (write, read, list).
     */
    public function missing(): array
    {
        return array_keys(array_filter(['write' => !$this->write, 'read' => !$this->read, 'list' => !$this->list]));
    }

    /**
     * @return array{write: bool, read: bool, list: bool, delete: bool}
     */
    public function toArray(): array
    {
        return ['write' => $this->write, 'read' => $this->read, 'list' => $this->list, 'delete' => $this->delete];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(!empty($data['write']), !empty($data['read']), !empty($data['list']), !empty($data['delete']));
    }
}
