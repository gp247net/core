<?php

namespace GP247\Core\Support\StepUp;

use RuntimeException;

/**
 * The user could not confirm who they are again. Reason codes are stable (screen strings).
 *
 * Not final: the backup feature keeps its historical class name as a subclass.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up
 */
class StepUpRefused extends RuntimeException
{
    public const REQUIRED = 'step_up_required';
    public const WRONG_PASSWORD = 'wrong_password';
    public const WRONG_CODE = 'wrong_code';
    public const THROTTLED = 'throttled';
    public const NO_ADMIN = 'no_admin';

    /**
     * @param string $reason
     * @param int    $retryAfter Seconds to wait (throttled).
     */
    final public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Step-up refused: ' . $reason);
    }
}
