{{--
    Dashboard block "System alerts" (row of admin_home, seeded at the top). Renders nothing — no
    markup at all — for anyone but an Administrator (NFR-SEC-admin-alert-administrator-only); the
    Livewire component re-checks the role on mount and on every action.

    @aidlc-unit admin-shell
    @aidlc-story US-admin-shell-system-alerts, US-AUI-005
    @aidlc-adr admin-shell_system-alert-registry
--}}
@if (app(\GP247\Core\AdminShell\Support\AdminAlertService::class)->allowed())
    <livewire:gp247-core::admin-alerts lazy />
@endif
