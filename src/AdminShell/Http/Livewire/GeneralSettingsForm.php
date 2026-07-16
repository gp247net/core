<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\StoreConfigForm;

/**
 * General admin settings (name/title/copyright) — root-store config group "".
 * Gated by `admin_config` (ADR-005).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class GeneralSettingsForm extends StoreConfigForm
{
    protected ?string $permission = 'admin_config';

    /**
     * @return array<int, string>
     */
    protected function keys(): array
    {
        return ['ADMIN_NAME', 'ADMIN_TITLE', 'hidden_copyright_footer', 'hidden_copyright_footer_admin'];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return [
            'hidden_copyright_footer' => 'bool',
            'hidden_copyright_footer_admin' => 'bool',
        ];
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.cfg_general');
    }
}
