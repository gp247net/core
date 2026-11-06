<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\Destination;
use GP247\Core\Backup\Domain\DestinationFactory;
use GP247\Core\Backup\Domain\DestinationFailure;
use GP247\Core\Backup\Domain\DestinationType;

/**
 * Types of external destination, gathered from gp247-config.backup.destinations (the core
 * registers s3 and sftp; BackupPro appends its own at boot). An entry that is not a
 * DestinationFactory class is skipped and reported — one broken plugin never hides the others.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters, backup-engine_core-module-layout
 */
final class DestinationRegistry
{
    /**
     * @return array<string, DestinationType> Registered types by key (available or not).
     */
    public function types(): array
    {
        $types = [];
        foreach ((array) config('gp247-config.backup.destinations', []) as $key => $class) {
            try {
                if (!is_string($class) || !class_exists($class) || !is_subclass_of($class, DestinationFactory::class)) {
                    throw new \InvalidArgumentException('not a destination factory');
                }
                $type = $class::type();
                if ($type->key !== $key || $type->factory !== $class) {
                    throw new \InvalidArgumentException('key or factory mismatch');
                }
                $types[$type->key] = $type;
            } catch (\Throwable $e) {
                gp247_report('Backup destination type "' . (is_string($key) ? $key : '?') . '" skipped: ' . $e->getMessage());
            }
        }

        return $types;
    }

    /**
     * @param string $key
     * @return DestinationType|null
     */
    public function type(string $key): ?DestinationType
    {
        return $this->types()[$key] ?? null;
    }

    /**
     * @param string                $key
     * @param array<string, mixed>  $fields  Clean plain fields.
     * @param array<string, string> $secrets Decrypted secrets.
     * @param bool                  $learnHostKey
     * @return Destination
     * @throws DestinationFailure unavailable | invalid_config | …
     */
    public function build(string $key, array $fields, #[\SensitiveParameter] array $secrets, bool $learnHostKey = false): Destination
    {
        $type = $this->type($key);
        if ($type === null) {
            throw new DestinationFailure(DestinationFailure::INVALID_CONFIG, 'unknown type');
        }
        if (!$type->available()) {
            throw new DestinationFailure(DestinationFailure::UNAVAILABLE, $type->key);
        }

        return $type->factory::build($fields, $secrets, $learnHostKey);
    }
}
