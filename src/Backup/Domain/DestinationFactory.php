<?php

namespace GP247\Core\Backup\Domain;

/**
 * What a destination type registers in gp247-config.backup.destinations (the class name): its
 * description, the checks of a configuration, and how to build the destination from a
 * configuration whose secrets were just decrypted.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp
 * @aidlc-adr backup-storage_optional-adapters
 */
interface DestinationFactory
{
    /**
     * @return DestinationType
     */
    public static function type(): DestinationType;

    /**
     * @param array<string, mixed>  $fields  Clean plain fields.
     * @param array<string, string> $secrets Decrypted secret fields (may be empty strings).
     * @return list<string> Problems as "<field>:<code>" (empty = valid).
     */
    public static function validate(array $fields, #[\SensitiveParameter] array $secrets): array;

    /**
     * @param array<string, mixed>  $fields
     * @param array<string, string> $secrets
     * @param bool                  $learnHostKey True on "Test the connection": an unknown host key is reported, not refused.
     * @return Destination
     * @throws DestinationFailure
     */
    public static function build(array $fields, #[\SensitiveParameter] array $secrets, bool $learnHostKey = false): Destination;
}
