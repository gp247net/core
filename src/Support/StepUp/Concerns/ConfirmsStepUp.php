<?php

namespace GP247\Core\Support\StepUp\Concerns;

use GP247\Core\Support\StepUp\StepUpGate;
use GP247\Core\Support\StepUp\StepUpRefused;

/**
 * "Confirm it is you" box for any admin Livewire screen: an action that needs a recent
 * re-confirmation calls requireStepUp(); when the proof is missing, the box opens and the
 * action runs once the user confirmed. Only actions listed by stepUpActions() can be resumed —
 * the pending action is a public (client-side) property, so it is never trusted beyond that
 * list, and the resumed action checks its own right again. The typed values are cleared right
 * after use; their property names contain "password" / "secret" so the operation log drops them.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr personal-data-registry_shared-step-up
 */
trait ConfirmsStepUp
{
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
     * @return StepUpGate The gate (and so the session proof) this screen uses.
     */
    abstract protected function stepUpGate(): StepUpGate;

    /**
     * @param string $reason     StepUpRefused reason code.
     * @param int    $retryAfter Seconds to wait when throttled.
     * @return string Message shown in the box.
     */
    protected function stepUpErrorText(string $reason, int $retryAfter): string
    {
        return gp247_language_render('admin.step_up.' . $reason, ['seconds' => $retryAfter]);
    }

    /**
     * @return string Browser event sent once the confirmation passed (the box removes itself).
     */
    protected function stepUpDoneEvent(): string
    {
        return 'gp247-step-up-done';
    }

    /**
     * @param string                     $action Method to run after confirmation.
     * @param list<string|int|bool|null> $args
     * @return bool True when the user confirmed recently (the caller goes on now).
     */
    protected function requireStepUp(string $action, array $args = []): bool
    {
        if ($this->stepUpGate()->isFresh()) {
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
            $this->stepUpGate()->confirm($password, $code);
        } catch (StepUpRefused $e) {
            $this->stepUpError = $this->stepUpErrorText($e->reason, $e->retryAfter);

            return;
        }
        $this->stepUpOpen = false;
        $this->stepUpError = null;
        // WHY: an action that ends in a redirect to a file download (the page stays) gets no
        // re-render, so the browser hides the box itself on this event.
        $this->dispatch($this->stepUpDoneEvent());
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
        return $this->stepUpGate()->codeRequired();
    }
}
