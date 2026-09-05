<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;

/**
 * Global settings screen (admin_config group "global") — the webhook endpoints
 * (Slack/Google/Chatwork). Cache and password-policy keys that also live in this
 * group have their own focused screens (CacheConfigForm, PasswordPolicyForm), so
 * this screen is scoped to its own keys only. The `api_connection_required` flag is
 * NOT edited here: it belongs to the dedicated API-connection screen
 * (ApiConnectionManager, admin::api_connection) which owns its label and help text;
 * duplicating it here only surfaced an unresolved label key. Gated by `admin_config`
 * (ADR-001/005).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
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
            'CHATWORK_CHAT_WEBHOOK_URL',
        ];
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.cfg_global');
    }
}
