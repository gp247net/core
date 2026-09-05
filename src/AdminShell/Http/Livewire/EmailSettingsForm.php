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
 * shown only while SMTP mode is on. All fields commit only on Save (ADR-005
 * amended 2026-09-05): the store-scoped smtp and email_action keys via the
 * inherited save()/$values pipeline, and the global `smtp_mode` flag via this
 * class's save() override (it lives in the 'global' group, outside keys()).
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
            // WHY: a fixed dropdown, not free text — the value maps token-exactly via
            // SmtpTransport::scheme() (only lowercase 'ssl'/'tls' work), so a typo like
            // "SSL" would silently downgrade to an unencrypted connection.
            'smtp_security' => 'select',
            'smtp_port' => 'number',
            // WHY: render as a masked password field, never plain text
            // (NFR-SEC-mail-secret-display, RISK-SEC-mail-password-plaintext).
            'smtp_password' => 'password',
        ];
    }

    /**
     * Select options for the smtp_security dropdown. Empty value = no encryption;
     * 'tls' = STARTTLS (port 587), 'ssl' = implicit TLS/smtps (port 465). Tokens are
     * lowercase to match SmtpTransport::scheme() exactly.
     *
     * @return array<string, array<int|string, string>>
     */
    protected function fieldOptions(): array
    {
        return [
            'smtp_security' => [
                '' => gp247_language_quickly('email.config_smtp.smtp_security_none', 'None (no encryption)'),
                'tls' => gp247_language_quickly('email.config_smtp.smtp_security_tls', 'TLS — STARTTLS (port 587)'),
                'ssl' => gp247_language_quickly('email.config_smtp.smtp_security_ssl', 'SSL — implicit TLS (port 465)'),
            ],
        ];
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.cfg_email');
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
     * Persist the global SMTP-mode flag as part of Save (no longer a live hook).
     *
     * WHY: smtp_mode lives in the 'global' config group, outside the store-scoped
     * keys() that the parent save() iterates, so it is written here rather than by
     * the inherited persistValue(). Called only by save() — nothing persists on
     * change, so a stray toggle never reaches the DB (ADR-005 amended 2026-09-05).
     *
     * @return void
     */
    private function persistSmtpMode(): void
    {
        // (string) cast: store_id is char(36); an int bind coerces the column to
        // DOUBLE (ADR compat-foundation_store-id-string-identity).
        $globalStore = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;

        AdminConfig::where('key', 'smtp_mode')
            ->where('group', 'global')
            ->where('store_id', (string) $globalStore)
            ->update(['value' => $this->smtpMode ? '1' : '0']);
    }

    /**
     * Persist the whole form on explicit Save: the global smtp_mode flag plus the
     * store-scoped email_action_* / smtp_* keys handled by the parent. No field
     * persists on change — the Save button is the single commit point, matching the
     * generic config screen (ADR-005 amended 2026-09-05).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function save(): void
    {
        // Authorize before the first write so an unauthorized Save persists nothing.
        $this->authorizeAction('update');
        $this->persistSmtpMode();

        // Parent persists keys() (email_action_* + smtp_*) and emits the single
        // "setting saved" toast for the whole form.
        parent::save();
    }

    /**
     * Build the environment-aware delivery guidance shown above the settings.
     *
     * Read-only (never probes the OS): derives a recommended state from the queue
     * toggle, QUEUE_CONNECTION and the auto-scheduler flag so the site owner sees
     * exactly which setup applies and — when relevant — the cron line to copy.
     *
     * States:
     *  - direct        : queue off → mail sent in-request, no cron needed.
     *  - queue_sync    : queue on but QUEUE_CONNECTION=sync → still synchronous.
     *  - queue_auto    : queue on, real connection, GP247 auto-schedules the drain
     *                    → one standard `schedule:run` cron is enough.
     *  - queue_manual  : queue on, real connection, auto-scheduler disabled
     *                    → a persistent worker (supervisor) must drain the queue.
     *
     * @return array{state:string, connection:string, cron:string}
     *
     * @aidlc-unit admin-shell-rbac
     * @aidlc-story US-AUI-smtp-secret-queue-guard
     * @aidlc-adr compat-foundation_mail-delivery-hardening
     */
    protected function mailGuide(): array
    {
        $connection = (string) config('queue.default');
        $queueOn = !empty($this->values['email_action_queue']);
        $autoScheduler = (bool) config('gp247-config.mail.schedule_queue_worker', true);

        $state = \GP247\Core\Mail\MailQueueAdvisor::guideState($queueOn, $connection, $autoScheduler);

        return [
            'state' => $state,
            'connection' => $connection,
            'cron' => '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1',
        ];
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
        $options = $configs->mapWithKeys(fn ($c) => [$c->key => $this->optionsOf($c->key)])->all();

        return view('gp247-admin::livewire.email-settings', [
            'configs' => $configs,
            'types' => $types,
            'options' => $options,
            'modeKeys' => $this->modeKeys(),
            'smtpKeys' => $this->smtpKeys(),
            'mailGuide' => $this->mailGuide(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->heading()]);
    }
}
