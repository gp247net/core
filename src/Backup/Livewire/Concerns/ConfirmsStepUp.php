<?php

namespace GP247\Core\Backup\Livewire\Concerns;

use GP247\Core\Backup\Application\StepUpService;
use GP247\Core\Backup\Domain\StepUpRefused;

/**
 * "Confirm it is you" box for backup screens: an action that needs a recent re-confirmation
 * calls requireStepUp(); when the proof is missing, the box opens and the action runs once the
 * admin confirmed. Only actions listed by stepUpActions() can be resumed — the pending action is
 * a public (client-side) property, so it is never trusted beyond that list, and the resumed
 * action checks its own right again. The typed values are cleared right after use; their
 * property names contain "password" / "secret" so the operation log drops them.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
trait ConfirmsStepUp
{
    /** Browser event sent once the confirmation passed; the box listens to it (partials/step-up). */
    public const STEP_UP_DONE_EVENT = 'gp247-backup-step-up-done';

    public bool $stepUpOpen = false;
    public string $stepUpPassword = '';
    public string $stepUpSecretCode = '';
    public ?string $stepUpError = null;
    public string $stepUpAction = '';
    /** @var list<string|int|bool|null> */
    public array $stepUpArgs = [];

    /**
     * @return list<string> Methods that may be resumed after confirmation.
     */
    abstract protected function stepUpActions(): array;

    /**
     * @param string                     $action Method to run after confirmation.
     * @param list<string|int|bool|null> $args
     * @return bool True when the admin confirmed recently (the caller goes on now).
     */
    protected function requireStepUp(string $action, array $args = []): bool
    {
        if (app(StepUpService::class)->isFresh()) {
            return true;
        }
        $this->stepUpAction = $action;
        $this->stepUpArgs = array_values($args);
        $this->stepUpError = null;
        $this->stepUpOpen = true;

        return false;
    }

    /**
     * @return void
     */
    public function confirmStepUp(): void
    {
        $password = $this->stepUpPassword;
        $code = $this->stepUpSecretCode;
        $this->stepUpPassword = '';
        $this->stepUpSecretCode = '';
        try {
            app(StepUpService::class)->confirm($password, $code);
        } catch (StepUpRefused $e) {
            $this->stepUpError = gp247_language_render('admin.backup.step_up.' . $e->reason, ['seconds' => $e->retryAfter]);

            return;
        }
        $this->stepUpOpen = false;
        $this->stepUpError = null;
        // WHY: an action that ends in a redirect to a file download (the page stays) gets no
        // re-render, so the browser hides the box itself on this event.
        $this->dispatch(self::STEP_UP_DONE_EVENT);
        $action = $this->stepUpAction;
        $args = $this->stepUpArgs;
        $this->stepUpAction = '';
        $this->stepUpArgs = [];
        if (in_array($action, $this->stepUpActions(), true)) {
            $this->{$action}(...$args);
        }
    }

    /**
     * @return void
     */
    public function cancelStepUp(): void
    {
        $this->reset(['stepUpOpen', 'stepUpPassword', 'stepUpSecretCode', 'stepUpError', 'stepUpAction', 'stepUpArgs']);
    }

    /**
     * @return bool For the view: whether the box asks for a second-step code.
     */
    protected function stepUpNeedsCode(): bool
    {
        return app(StepUpService::class)->codeRequired();
    }
}
