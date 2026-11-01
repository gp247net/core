<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Core\Api\Sync\EventType;
use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Api\Sync\OutboundUrlRejected;
use GP247\Core\Api\Sync\WebhookDispatcher;
use GP247\Core\Models\AdminApiWebhook;
use GP247\Core\Models\AdminApiWebhookDelivery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * Webhooks block of the API screen: an administrator registers the URLs that receive the
 * sync events of the chosen types, sees each signing secret once (when created or
 * regenerated) and the last deliveries. Only administrators — a webhook sends data out
 * of the site. Every URL passes OutboundUrlGuard (https to a public address).
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-register
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
class ApiWebhookManager extends GP247AdminComponent
{
    /** Deliveries listed per webhook. */
    private const HISTORY = 20;

    protected ?string $permission = 'admin_api_connection';

    /** @var int|null Webhook being edited; null when adding. */
    #[Locked]
    public ?int $editingId = null;

    /** @var string */
    public string $url = '';

    /** @var string[] Followed event types. */
    public array $eventTypes = [];

    /** @var bool */
    public bool $active = true;

    /** @var int|null Webhook whose deliveries are shown. */
    #[Locked]
    public ?int $historyFor = null;

    /**
     * Secret just created or regenerated, rendered once in this response. WHY protected:
     * Livewire neither keeps it in wire:snapshot nor sends it back, and the action log
     * never sees it.
     *
     * @var string|null
     */
    protected ?string $revealedSecret = null;

    /**
     * @return void
     * @throws AuthorizationException When the user is not an administrator.
     */
    public function mount(): void
    {
        parent::mount();
        $this->assertAdministrator();
    }

    /**
     * Add the webhook in the form, or save the one being edited.
     *
     * @return void
     */
    public function save(): void
    {
        $this->authorizeWrite();
        $types = array_keys(EventType::all());
        $this->validate([
            'url' => ['required', 'string', 'max:500', function ($attribute, $value, $fail) {
                try {
                    OutboundUrlGuard::check((string) $value);
                } catch (OutboundUrlRejected $e) {
                    $fail(gp247_language_render('admin.api_webhook.url_refused'));
                }
            }],
            'eventTypes' => ['required', 'array', 'min:1'],
            'eventTypes.*' => ['string', 'in:' . implode(',', $types)],
        ], [], [
            'url' => gp247_language_render('admin.api_webhook.url'),
            'eventTypes' => gp247_language_render('admin.api_webhook.event_types'),
        ]);

        $payload = ['url' => trim($this->url), 'event_types' => array_values(array_unique($this->eventTypes)), 'status' => $this->active ? 1 : 0];
        if ($this->editingId !== null) {
            $webhook = AdminApiWebhook::findOrFail($this->editingId);
            $reopened = $this->active && !$webhook->status;
            // Turning a webhook back on starts its failure count again and resumes what it was owed.
            $webhook->update($payload + ($reopened ? ['consecutive_failures' => 0, 'disabled_reason' => null] : []));
            if ($reopened) {
                WebhookDispatcher::wake();
            }
        } else {
            $secret = AdminApiWebhook::newSecret();
            AdminApiWebhook::create($payload + ['secret' => $secret]);
            $this->revealedSecret = $secret;
        }
        WebhookDispatcher::forgetWebhooks();
        $this->resetForm();
        $this->notify('success', gp247_language_render('action.update_success'));
    }

    /**
     * Load a webhook into the form.
     *
     * @param int $id
     * @return void
     */
    public function edit(int $id): void
    {
        $this->assertAdministrator();
        $webhook = AdminApiWebhook::findOrFail($id);
        $this->editingId = (int) $webhook->id;
        $this->url = (string) $webhook->url;
        $this->eventTypes = array_values((array) $webhook->event_types);
        $this->active = (bool) $webhook->status;
        $this->resetValidation();
    }

    /**
     * Replace the signing secret of a webhook (the old one stops working at once).
     *
     * @param int $id
     * @return void
     */
    public function regenerateSecret(int $id): void
    {
        $this->authorizeWrite();
        $secret = AdminApiWebhook::newSecret();
        AdminApiWebhook::findOrFail($id)->update(['secret' => $secret]);
        $this->revealedSecret = $secret;
        $this->notify('success', gp247_language_render('action.update_success'));
    }

    /**
     * Remove a webhook and its delivery history.
     *
     * @param int $id
     * @return void
     */
    public function delete(int $id): void
    {
        $this->authorizeWrite();
        AdminApiWebhookDelivery::where('webhook_id', $id)->delete();
        AdminApiWebhook::where('id', $id)->delete();
        WebhookDispatcher::forgetWebhooks();
        if ($this->editingId === $id) {
            $this->resetForm();
        }
        if ($this->historyFor === $id) {
            $this->historyFor = null;
        }
        $this->notify('success', gp247_language_render('action.delete_success'));
    }

    /**
     * Show (or hide) the last deliveries of a webhook.
     *
     * @param int $id
     * @return void
     */
    public function toggleHistory(int $id): void
    {
        $this->assertAdministrator();
        $this->historyFor = $this->historyFor === $id ? null : $id;
    }

    /**
     * @return void
     */
    public function resetForm(): void
    {
        $this->editingId = null;
        $this->url = '';
        $this->eventTypes = [];
        $this->active = true;
        $this->resetValidation();
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.api-webhook-manager', [
            'webhooks' => AdminApiWebhook::orderBy('id')->get(),
            'types' => array_keys(EventType::all()),
            'deliveries' => $this->historyFor === null ? collect()
                : AdminApiWebhookDelivery::where('webhook_id', $this->historyFor)->orderByDesc('id')->limit(self::HISTORY)->get(),
            'revealedSecret' => $this->revealedSecret,
        ]);
    }

    /**
     * Screen write permission, and the administrator role.
     *
     * @return void
     * @throws AuthorizationException
     */
    private function authorizeWrite(): void
    {
        $this->authorizeAction('save');
        $this->assertAdministrator();
    }

    /**
     * @return void
     * @throws AuthorizationException When the user is not an administrator.
     */
    private function assertAdministrator(): void
    {
        if (!app(AdminUserContract::class)->isAdministrator()) {
            throw AuthorizationException::fromReason('webhooks are managed by administrators only');
        }
    }
}
