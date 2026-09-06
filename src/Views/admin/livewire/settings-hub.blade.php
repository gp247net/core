{{--
    Configuration hub (ADR-005): tabs grouping the related settings screens. Each
    pane embeds an existing config Livewire child; the tab chrome is client-side
    (Alpine) so children mount once and keep state when switching tabs.

    Core tabs (general/email/custom) render only when the viewer may see the core
    config ($coreAllowed); contributed tabs ($extraTabs, from SettingsHubTabRegistry)
    are already filtered to those the viewer is authorized for — a pane is never
    embedded for a tab the viewer cannot access, so a child's mount() cannot throw
    and break the page (ADR admin-shell_config-hub-tab-registry).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-005, US-UI-008, US-admin-shell-config-hub-tab-registry
    @aidlc-adr ADR-005, admin-shell_config-hub-tab-registry
--}}
@php
    $navTabs = [];
    if ($coreAllowed) {
        $navTabs['general'] = gp247_language_render('admin.cfg_general');
        $navTabs['email']   = gp247_language_render('admin.cfg_email');
        $navTabs['custom']  = gp247_language_render('admin.cfg_custom');
    }
    foreach ($extraTabs as $extraTab) {
        $navTabs[$extraTab['key']] = gp247_language_render($extraTab['label']);
    }
@endphp
<div>
    <x-gp247::tabs :tabs="$navTabs">
        @if ($coreAllowed)
            <div x-show="tab === 'general'" x-cloak>
                <livewire:gp247-core::general-settings-form />
            </div>
            <div x-show="tab === 'email'" x-cloak>
                <livewire:gp247-core::email-settings-form />
            </div>
            <div x-show="tab === 'custom'" x-cloak>
                <livewire:gp247-core::custom-config-form />
            </div>
        @endif
        @foreach ($extraTabs as $extraTab)
            {{-- WHY: contributed tabs are named at runtime (registry), so embed via
                 the @livewire directive with a dynamic name rather than a fixed tag. --}}
            <div x-show="tab === @js($extraTab['key'])" x-cloak>
                @livewire($extraTab['component'], [], key('hubtab-' . $extraTab['key']))
            </div>
        @endforeach
    </x-gp247::tabs>
</div>
