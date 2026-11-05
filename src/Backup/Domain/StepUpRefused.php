<?php

namespace GP247\Core\Backup\Domain;

use RuntimeException;

/**
 * The admin could not confirm who they are again. Reason codes are stable (screen strings).
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class StepUpRefused extends RuntimeException
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
    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Step-up refused: ' . $reason);
    }
}
