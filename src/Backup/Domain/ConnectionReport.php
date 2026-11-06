<?php

namespace GP247\Core\Backup\Domain;

/**
 * Result of "Test the connection": the rights found, the first error met (a fixed code), and —
 * for SFTP — the server's host key fingerprint. Stored (without any secret) so the copy job
 * knows whether it may read back what it wrote, and retention whether it may delete.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-immutable-guide
 * @aidlc-adr backup-storage_optional-adapters
 */
final class ConnectionReport
{
    /**
     * @param DestinationCapabilities $capabilities
     * @param string|null             $error       DestinationFailure reason, null when none.
     * @param string|null             $fingerprint SHA256:… of the SFTP host key.
     * @param int                     $at          Unix time of the test.
     */
    public function __construct(
        public readonly DestinationCapabilities $capabilities,
        public readonly ?string $error = null,
        public readonly ?string $fingerprint = null,
        public readonly int $at = 0,
    ) {
    }

    /**
     * @return bool The destination can be used as it is.
     */
    public function ok(): bool
    {
        return $this->error === null && $this->capabilities->usable();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['capabilities' => $this->capabilities->toArray(), 'error' => $this->error, 'fingerprint' => $this->fingerprint, 'at' => $this->at];
    }

    /**
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $error = isset($data['error']) && is_string($data['error']) && preg_match('/^[a-z_]{1,40}$/D', $data['error']) === 1 ? $data['error'] : null;
        $fingerprint = isset($data['fingerprint']) && is_string($data['fingerprint']) ? mb_substr($data['fingerprint'], 0, 100) : null;

        return new self(DestinationCapabilities::fromArray((array) ($data['capabilities'] ?? [])), $error, $fingerprint, (int) ($data['at'] ?? 0));
    }
}
