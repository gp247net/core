<?php

namespace GP247\Core\AdminShell\Domain;

/**
 * Immutable outcome of an authorization check: allow or deny, plus a
 * machine-stable reason used for logging and deny responses.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
final class AuthorizationDecision
{
    /**
     * @param bool   $allowed Whether the action is permitted.
     * @param string $reason  Short, stable reason code/phrase for the outcome.
     */
    private function __construct(
        private readonly bool $allowed,
        private readonly string $reason,
    ) {
    }

    /**
     * @param string $reason Why access was granted.
     * @return self An "allow" decision.
     */
    public static function allow(string $reason): self
    {
        return new self(true, $reason);
    }

    /**
     * @param string $reason Why access was denied.
     * @return self A "deny" decision.
     */
    public static function deny(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * @return bool True when the action is permitted.
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * @return string The reason behind this decision.
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
