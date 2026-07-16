<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use Illuminate\Contracts\View\View;

/**
 * Configuration hub (ADR-005): a single screen that groups the related settings
 * screens — General, Email/SMTP, Social and Global — into tabs, mirroring the
 * legacy tabbed config layout. Each tab embeds the existing config Livewire
 * component as a nested child, so their live-edit behaviour is reused unchanged.
 *
 * Gated by `admin_config` like the screens it hosts; the nested children also
 * enforce their own Layer-2 checks (defense in depth).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-005, US-UI-008
 * @aidlc-adr ADR-001, ADR-005
 */
class SettingsHub extends GP247AdminComponent
{
    protected ?string $permission = 'admin_config';

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.settings-hub')
            ->layout('gp247-admin::layouts.admin', ['title' => gp247_language_render('admin.cfg_title')]);
    }
}
