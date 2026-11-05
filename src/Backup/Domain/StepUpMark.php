<?php

namespace GP247\Core\Backup\Domain;

/**
 * Proof that an admin typed their sign-in password again (and the second-step code when the
 * site has one) a moment ago, in this browser session. Valid for one admin, one session id,
 * a few minutes — a stolen session cookie on its own is not enough to download or restore.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class StepUpMark
{
    /**
     * @param string $adminId
     * @param string $sessionId
     * @param int    $at Unix time of the confirmation.
     */
    public function __construct(
        public readonly string $adminId,
        public readonly string $sessionId,
        public readonly int $at,
    ) {
    }

    /**
     * @param string $adminId
     * @param string $sessionId
     * @param int    $now     Unix time.
     * @param int    $seconds Validity.
     * @return bool
     */
    public function isValidFor(string $adminId, string $sessionId, int $now, int $seconds): bool
    {
        return $adminId !== ''
            && $sessionId !== ''
            && hash_equals($this->adminId, $adminId)
            && hash_equals($this->sessionId, $sessionId)
            && $this->at <= $now
            && $now - $this->at < $seconds;
    }

    /**
     * @return array{admin: string, session: string, at: int}
     */
    public function toArray(): array
    {
        return ['admin' => $this->adminId, 'session' => $this->sessionId, 'at' => $this->at];
    }

    /**
     * @param mixed $data Session value.
     * @return self|null Null when the value is not a mark.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['admin'] ?? null) || !is_string($data['session'] ?? null) || !is_int($data['at'] ?? null)) {
            return null;
        }

        return new self($data['admin'], $data['session'], $data['at']);
    }
}
