<?php

namespace GP247\Core\AdminShell\Domain;

use RuntimeException;

/**
 * Raised when an admin action is denied by the authorization core. The carried
 * reason mirrors AuthorizationDecision::reason() for logging and deny responses.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
final class AuthorizationException extends RuntimeException
{
    /**
     * @param string $reason Stable deny reason from the authorization decision.
     * @return self Exception carrying the deny reason as its message.
     */
    public static function fromReason(string $reason): self
    {
        return new self($reason);
    }
}
