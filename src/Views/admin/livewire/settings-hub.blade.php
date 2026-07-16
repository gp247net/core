{{--
    Configuration hub (ADR-005): tabs grouping the related settings screens. Each
    pane embeds an existing config Livewire child; the tab chrome is client-side
    (Alpine) so children mount once and keep state when switching tabs.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005, US-UI-008
    @aidlc-adr ADR-005
--}}
<div>
    <x-gp247::tabs :tabs="[
        'general' => gp247_language_render('admin.cfg_general'),
        'email'   => gp247_language_render('admin.cfg_email'),
        'custom'  => gp247_language_render('admin.cfg_custom'),
    ]">
        <div x-show="tab === 'general'" x-cloak>
            <livewire:gp247-core::general-settings-form />
        </div>
        <div x-show="tab === 'email'" x-cloak>
            <livewire:gp247-core::email-settings-form />
        </div>
        <div x-show="tab === 'custom'" x-cloak>
            <livewire:gp247-core::custom-config-form />
        </div>
    </x-gp247::tabs>
</div>
