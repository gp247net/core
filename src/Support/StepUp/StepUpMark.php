<?php

namespace GP247\Core\Support\StepUp;

/**
 * Proof that a signed-in user typed their password again (and the second-step code when the
 * site has one) a moment ago, in this browser session. Valid for one account, one session id,
 * a few minutes — a stolen session cookie on its own is not enough for a dangerous action.
 *
 * Not final: the backup feature keeps its historical class name as a subclass.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up
 */
class StepUpMark
{
    /**
     * @param string $adminId   Account id (the array key stays "admin" for stored marks).
     * @param string $sessionId
     * @param int    $at        Unix time of the confirmation.
     */
    final public function __construct(
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
     * @return static|null Null when the value is not a mark.
     */
    public static function fromArray(mixed $data): ?static
    {
        if (!is_array($data) || !is_string($data['admin'] ?? null) || !is_string($data['session'] ?? null) || !is_int($data['at'] ?? null)) {
            return null;
        }

        return new static($data['admin'], $data['session'], $data['at']);
    }
}
