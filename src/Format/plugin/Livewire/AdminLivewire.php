<?php
#App\GP247\Plugins\Extension_Key\Livewire\AdminLivewire.php

namespace App\GP247\Plugins\Extension_Key\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;

/**
 * Sample admin Livewire component for a GP247 plugin (TailAdmin shell).
 *
 * Extends GP247AdminComponent so the plugin screen inherits Layer-2 RBAC
 * (read authorization on mount, toast helpers) and the shared admin layout,
 * exactly like core/front/shop admin screens. Replaces the legacy
 * AdminController flow for plugins targeting the new Livewire standard.
 *
 * @aidlc-unit plugin-manager
 * @aidlc-story US-PLG-004
 */
class AdminLivewire extends GP247AdminComponent
{
    /**
     * Permission slug gating this component. When null, the permission is
     * inferred from the registered component name by the PermissionResolver
     * convention (same behaviour as core admin components).
     *
     * @var string|null
     */
    protected ?string $permission = null;

    /**
     * Render the plugin admin screen inside the shared TailAdmin layout.
     *
     * @return \Illuminate\Contracts\View\View
     *
     * @aidlc-unit plugin-manager
     * @aidlc-story US-PLG-004
     */
    public function render()
    {
        return view('Plugins/Extension_Key::livewire')
            ->layout('gp247-admin::layouts.admin', [
                'title' => trans('Plugins/Extension_Key::lang.title'),
            ]);
    }
}
