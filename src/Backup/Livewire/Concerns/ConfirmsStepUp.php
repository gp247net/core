<?php

namespace GP247\Core\Backup\Livewire\Concerns;

use GP247\Core\Backup\Application\StepUpService;
use GP247\Core\Support\StepUp\Concerns\ConfirmsStepUp as SharedConfirmsStepUp;
use GP247\Core\Support\StepUp\StepUpGate;

/**
 * "Confirm it is you" box for backup screens: the shared step-up box with the backup feature's
 * gate (its own session proof, validity and audit), texts and browser event.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download, personal-data-registry_shared-step-up
 */
trait ConfirmsStepUp
{
    use SharedConfirmsStepUp;

    /** Browser event sent once the confirmation passed; the box listens to it (partials/step-up). */
    public const STEP_UP_DONE_EVENT = 'gp247-backup-step-up-done';

    /**
     * @return StepUpGate
     */
    protected function stepUpGate(): StepUpGate
    {
        return app(StepUpService::class)->gate();
    }

    /**
     * @param string $reason
     * @param int    $retryAfter
     * @return string
     */
    protected function stepUpErrorText(string $reason, int $retryAfter): string
    {
        return gp247_language_render('admin.backup.step_up.' . $reason, ['seconds' => $retryAfter]);
    }

    /**
     * @return string
     */
    protected function stepUpDoneEvent(): string
    {
        return self::STEP_UP_DONE_EVENT;
    }
}
