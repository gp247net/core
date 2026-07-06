<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ConfigForm;

/**
 * Admin password-policy settings — a focused subset of the admin_config "global"
 * group (the admin_password_* keys consumed by PasswordValidationTrait). Gated by
 * `admin_config` (ADR-001/005).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005
 * @aidlc-adr ADR-001, ADR-005
 */
class PasswordPolicyForm extends ConfigForm
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
     * @return array<int, string>
     */
    protected function keys(): array
    {
        return [
            'admin_password_min',
            'admin_password_max',
            'admin_password_letter',
            'admin_password_mixedcase',
            'admin_password_number',
            'admin_password_symbol',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function fieldTypes(): array
    {
        return [
            'admin_password_min' => 'number',
            'admin_password_max' => 'number',
            'admin_password_letter' => 'bool',
            'admin_password_mixedcase' => 'bool',
            'admin_password_number' => 'bool',
            'admin_password_symbol' => 'bool',
        ];
    }

    /**
     * @return string
     */
    protected function heading(): string
    {
        return gp247_language_render('admin.core.cfg_password');
    }
}
