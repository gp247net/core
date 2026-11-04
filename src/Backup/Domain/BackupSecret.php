<?php

namespace GP247\Core\Backup\Domain;

/**
 * What the user typed to open backups: the backup password or the recovery key.
 * The value never appears in dumps, logs or serialized state.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-encryption, US-backup-engine-key-setup
 * @aidlc-adr backup-engine_envelope-encryption
 */
final class BackupSecret
{
    public const PASSWORD = 'password';
    public const RECOVERY = 'recovery';

    /**
     * @param string $kind  self::PASSWORD or self::RECOVERY.
     * @param string $value The secret text.
     */
    private function __construct(public readonly string $kind, #[\SensitiveParameter] private readonly string $value)
    {
    }

    /**
     * @param string $password Backup password.
     * @return self
     */
    public static function password(#[\SensitiveParameter] string $password): self
    {
        return new self(self::PASSWORD, $password);
    }

    /**
     * @param string|RecoveryKey $recovery Recovery key text or object.
     * @return self
     */
    public static function recovery(#[\SensitiveParameter] string|RecoveryKey $recovery): self
    {
        return new self(self::RECOVERY, $recovery instanceof RecoveryKey ? $recovery->display() : $recovery);
    }

    /**
     * @return string The secret text (only for the key service).
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'value' => '[hidden]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A backup secret must not be serialized.');
    }
}
