<?php

namespace GP247\Core\Backup\Domain;

/**
 * A kind of external destination as the settings screen shows it: its fields (secret ones are
 * write-only on the screen and stored encrypted), the fields that identify where the data goes
 * (changing one of them means the stored secrets are not used again), and whether the library it
 * needs is installed.
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-credential-secret
 * @aidlc-adr backup-storage_optional-adapters
 */
final class DestinationType
{
    public const KIND_TEXT = 'text';
    public const KIND_NUMBER = 'number';
    public const KIND_BOOL = 'bool';
    public const KIND_TEXTAREA = 'textarea';

    /**
     * @param string                                                     $key          Type key (a-z0-9_, ≤ 20).
     * @param string                                                     $label        Language key of the name.
     * @param array<string, array{kind: string, required?: bool, default?: mixed, max?: int}> $fields Plain fields.
     * @param array<string, array{kind: string, required?: bool, max?: int}> $secrets   Secret fields.
     * @param list<string>                                               $identity     Plain fields naming where the data goes.
     * @param string                                                     $requiredClass Class of the library the type needs.
     * @param string                                                     $install      Command that installs that library.
     * @param class-string<DestinationFactory>                           $factory
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $fields,
        public readonly array $secrets,
        public readonly array $identity,
        public readonly string $requiredClass,
        public readonly string $install,
        public readonly string $factory,
    ) {
        if (preg_match('/^[a-z0-9_]{1,20}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('Destination type key must be a-z0-9_ (max 20).');
        }
    }

    /**
     * @return bool The library this type needs is installed.
     */
    public function available(): bool
    {
        return $this->requiredClass === '' || class_exists($this->requiredClass) || interface_exists($this->requiredClass);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed> Only this type's plain fields, typed, with defaults.
     */
    public function cleanFields(array $values): array
    {
        $out = [];
        foreach ($this->fields as $name => $field) {
            $value = $values[$name] ?? ($field['default'] ?? null);
            $out[$name] = match ($field['kind']) {
                self::KIND_BOOL => (bool) $value,
                self::KIND_NUMBER => $value === null || $value === '' ? null : (int) $value,
                default => trim((string) $value),
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return bool Both point to the same place (identity fields equal).
     */
    public function sameIdentity(array $a, array $b): bool
    {
        foreach ($this->identity as $name) {
            if ((string) ($a[$name] ?? '') !== (string) ($b[$name] ?? '')) {
                return false;
            }
        }

        return true;
    }
}
