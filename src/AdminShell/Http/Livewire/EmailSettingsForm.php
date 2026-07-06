<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\StoreConfigForm;
use GP247\Core\Models\AdminConfig;
use Illuminate\Contracts\View\View;

/**
 * Email / SMTP settings (ADR-001/005). Gated by `admin_config`.
 *
 * Mirrors the legacy two-column layout: an "Email mode" card (email_action_*
 * keys + the global `smtp_mode` toggle) and an "SMTP configuration" card that is
 * shown only while SMTP mode is on. The store-scoped smtp and email_action keys
 * use the inherited live-edit pipeline ($values + updatedValues); the smtp_mode
 * toggle lives in the global config group and is persisted separately.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-UI-008
 * @aidlc-adr ADR-001, ADR-005
 */
class EmailSettingsForm extends StoreConfigForm
{
    protected ?string $permission = 'admin_config';

    /** @var bool Whether outgoing mail uses SMTP (global config `smtp_mode`). */
    public bool $smtpMode = false;

    /**
     * Keys shown in the "Email mode" card.
     *
     * @return array<int, string>
     */
    protected function modeKeys(): array
    {
        return ['email_action_mode', 'email_action_queue'];
    }

    /**
     * Keys shown in the "SMTP configuration" card.
     *
     * @return array<int, string>
     */
    protected function smtpKeys(): array
    {
        return ['smtp_host', 'smtp_user', 'smtp_password', 'smtp_security', 'smtp_port', 'smtp_name', 'smtp_from'];
    }

    /**
     * @return array<int, string>
     */
    protected function keys(): array
    {
        return array_merge($this->modeKeys(), $this->smtpKeys());
    }

    /**
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return [
            'email_action_mode' => 'bool',
            'email_action_queue' => 'bool',
            'smtp_port' => 'number',
        ];
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.core.cfg_email');
    }

    /**
     * Seed the live-edit values, then load the global SMTP-mode flag.
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        // WHY: read the row directly (not the cached gp247_config_global helper)
        // so the toggle reflects the persisted value immediately after a change.
        $globalStore = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
        $value = AdminConfig::where('key', 'smtp_mode')
            ->where('group', 'global')
            ->where('store_id', $globalStore)
            ->value('value');

        $this->smtpMode = (bool) (int) $value;
    }

    /**
     * Persist the global SMTP-mode toggle the moment it changes (Layer-2 gated).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function updatedSmtpMode(): void
    {
        $this->authorizeAction('update');

        $globalStore = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;

        AdminConfig::where('key', 'smtp_mode')
            ->where('group', 'global')
            ->where('store_id', $globalStore)
            ->update(['value' => $this->smtpMode ? '1' : '0']);

        $this->notify('success', gp247_language_render('admin.core.setting_saved'));
    }

    /**
     * Render the two-card email/SMTP layout (overrides the generic config table).
     *
     * @return View
     */
    public function render(): View
    {
        $configs = $this->configs()->keyBy('key');
        $types = $configs->mapWithKeys(fn ($c) => [$c->key => $this->typeOf($c->key)])->all();

        return view('gp247-admin::livewire.email-settings', [
            'configs' => $configs,
            'types' => $types,
            'modeKeys' => $this->modeKeys(),
            'smtpKeys' => $this->smtpKeys(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->heading()]);
    }
}
