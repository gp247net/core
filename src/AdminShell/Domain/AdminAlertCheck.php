<?php

namespace GP247\Core\AdminShell\Domain;

use GP247\Core\AdminShell\Infrastructure\AdminAlert;

/**
 * One site-health check of the "System alerts" block (and of `gp247:doctor`).
 *
 * Implementations must not depend on the current HTTP request or session (the
 * same class runs from the CLI), must not call out over the network, and must
 * never put a secret value into the returned alert.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-alert-check-registry
 * @aidlc-adr admin-shell_system-alert-registry
 */
interface AdminAlertCheck
{
    /**
     * Stable key: cache key, `data-testid` suffix and doctor row name.
     *
     * @return string
     */
    public function key(): string;

    /**
     * How long (seconds) a result may be reused before evaluating again.
     *
     * @return int
     */
    public function ttl(): int;

    /**
     * Evaluate the condition now. May throw; callers isolate the failure.
     *
     * @return AdminAlert
     */
    public function evaluate(): AdminAlert;
}
