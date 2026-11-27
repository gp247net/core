<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;
use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Api\Sync\OutboundUrlRejected;

/**
 * Global settings screen (admin_config group "global") — the webhook endpoints that receive
 * the platform error reports: Slack, Google Chat and a third-party webhook taking
 * {"text": "..."} (sent by ReportNotifier). Cache and password-policy keys that also live
 * in this group have their own focused screens (CacheConfigForm, PasswordPolicyForm), so
 * this screen is scoped to its own keys only. The `api_connection_required` flag is NOT
 * edited here: it belongs to the dedicated API-connection screen (ApiConnectionManager,
 * admin::api_connection) which owns its label and help text. Gated by `admin_config`
 * (ADR-001/005).
 *
 * The URLs are secrets (anyone holding one can post into the channel): the fields are
 * write-only like the SMTP password, stored encrypted, and every non-empty URL must pass
 * OutboundUrlGuard (https to a public address) before anything is saved.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-CMP-report-channels-env
 * @aidlc-adr ADR-001, ADR-005, compat-foundation_report-channels-env
 */
class GlobalConfigForm extends ConfigForm
{
    protected ?string $permission = 'admin_config';

    /**
     * @return string
     */
    protected function group(): string
    {
        return 'global';
    }

    /**
     * Scope to the webhook keys; the rest of the "global" group belongs to the
     * dedicated cache / password-policy / api-connection screens.
     *
     * @return array<int, string>
     */
    protected function keys(): array
    {
        return [
            'LOG_SLACK_WEBHOOK_URL',
            'GOOGLE_CHAT_WEBHOOK_URL',
            'THIRD_PARTY_WEBHOOK_URL',
        ];
    }

    /**
     * Every webhook URL is a write-only secret.
     *
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return array_fill_keys($this->keys(), 'password');
    }

    /**
     * Format hint next to the third-party field: it must accept the Slack-style text payload.
     *
     * @return array<string, string>
     */
    protected function fieldHints(): array
    {
        return ['THIRD_PARTY_WEBHOOK_URL' => '{"text": "..."}'];
    }

    /**
     * One block explaining the screen: where reports go, that URLs are hidden once saved, the
     * throttle, and the .env fallback for a database outage.
     *
     * @return array<int, array{id: string, title: string, hint: string, keys: array<int, string>}>
     */
    protected function sections(): array
    {
        return [[
            'id' => 'report-webhooks',
            'title' => gp247_language_render('admin.config.webhook_section_title'),
            'hint' => gp247_language_render('admin.config.webhook_section_hint'),
            'keys' => $this->keys(),
        ]];
    }

    /**
     * Under each field: where to get the URL and an example.
     *
     * @return array<string, string>
     */
    protected function fieldNotes(): array
    {
        $notes = [];
        foreach ($this->keys() as $key) {
            $notes[$key] = gp247_language_render('admin.config.' . $key . '_note');
        }

        return $notes;
    }

    /**
     * Example URL shown in each empty field (format only; not a real webhook).
     *
     * @return array<string, string>
     */
    protected function fieldPlaceholders(): array
    {
        return [
            'LOG_SLACK_WEBHOOK_URL' => 'https://hooks.slack.com/services/T.../B.../...',
            'GOOGLE_CHAT_WEBHOOK_URL' => 'https://chat.googleapis.com/v1/spaces/AAAAbCdEfGh/messages?key=xxxx&token=xxxx',
            'THIRD_PARTY_WEBHOOK_URL' => 'https://chat.example.com/hooks/xxxxxxxxxxxxxxxx',
        ];
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.cfg_global');
    }

    /**
     * Refuse the whole save when a typed URL may not be called; an empty field keeps the stored URL.
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function save(): void
    {
        $this->authorizeAction('update');
        $this->resetErrorBag();

        $refused = false;
        foreach ($this->keys() as $key) {
            $url = trim((string) ($this->values[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            try {
                OutboundUrlGuard::check($url);
                $this->values[$key] = $url;
            } catch (OutboundUrlRejected $e) {
                $this->addError('values.' . $key, gp247_language_render('admin.config.webhook_url_refused'));
                $refused = true;
            }
        }
        if ($refused) {
            return;
        }

        parent::save();
    }
}
