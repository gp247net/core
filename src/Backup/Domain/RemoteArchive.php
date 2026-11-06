<?php

namespace GP247\Core\Backup\Domain;

/**
 * An archive folder found in the external destination, described from the destination's file
 * list only (names, sizes, times) — no content read, no backup password needed.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-fetch-remote
 * @aidlc-adr backup-storage_optional-adapters
 */
final class RemoteArchive
{
    /**
     * @param string             $id
     * @param array<string, int> $files       name => bytes.
     * @param int|null           $modifiedAt  Unix time of the newest file.
     * @param bool               $inCatalogue The archive is in this site's catalogue.
     */
    public function __construct(
        public readonly string $id,
        public readonly array $files,
        public readonly ?int $modifiedAt,
        public readonly bool $inCatalogue,
    ) {
    }

    /**
     * @return bool The header is there (it is copied last).
     */
    public function complete(): bool
    {
        return isset($this->files[RemoteLayout::HEADER], $this->files[RemoteLayout::MANIFEST]);
    }

    /**
     * @return int
     */
    public function bytes(): int
    {
        return array_sum($this->files);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'bytes' => $this->bytes(),
            'files' => count($this->files),
            'modified_at' => $this->modifiedAt,
            'complete' => $this->complete(),
            'in_catalogue' => $this->inCatalogue,
        ];
    }
}
