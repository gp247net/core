{{--
    "System alerts" dashboard block (US-admin-shell-system-alerts, ADR admin-shell_system-alerts-page):
    one summary line — shield tile coloured by the worst level, a sentence, up to two critical names,
    counts per level and a link to the "System alerts" page (list, details, "Check again" live there).
    Only Tailwind class combinations already present in the core admin bundle (rule gp247.md §3a).

    @aidlc-unit admin-shell
    @aidlc-story US-admin-shell-system-alerts

    Variables: $worst (critical|warning|info|ok), $counts (array<level, int>), $failingCount (int),
    $criticalNames (string[]), $criticalMore (int), $total (int), $checkedAt (int|null).
--}}
@php
    use GP247\Core\AdminShell\Support\AdminAlertStyle;

    $gp247AlertTime = $checkedAt
        ? \Carbon\Carbon::createFromTimestamp($checkedAt)->setTimezone(config('app.timezone'))->format('H:i d/m/Y')
        : '';
    $gp247AlertSummary = match ($worst) {
        'critical' => gp247_language_render('admin.alerts.summary_critical', ['count' => $counts['critical'] ?? 0]),
        'ok' => gp247_language_render('admin.alerts.summary_ok', ['count' => $total]),
        default => gp247_language_render('admin.alerts.summary_review', ['count' => $failingCount]),
    };
    // A warning-only site shows amber, not blue, even when its worst item is informational.
    $gp247AlertTile = $worst === 'info' ? 'warning' : $worst;
@endphp

<div>
    <x-gp247::card data-testid="admin-alerts">
        <div class="flex flex-wrap items-center gap-4" data-testid="admin-alerts-summary" data-level="{{ $worst }}">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ AdminAlertStyle::tile($gp247AlertTile) }}">
                <i class="fas fa-shield-alt"></i>
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $gp247AlertSummary }}</p>
                @if ($criticalNames !== [])
                    <p class="mt-0.5 truncate text-sm text-gray-500 dark:text-gray-400">
                        {{ implode(' · ', $criticalNames) }}@if ($criticalMore > 0) · {{ gp247_language_render('admin.alerts.and_more', ['count' => $criticalMore]) }}@endif
                    </p>
                @elseif ($gp247AlertTime)
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.alerts.checked_at', ['time' => $gp247AlertTime]) }}</p>
                @endif
            </div>
            @if (!empty($counts['critical']))
                <x-gp247::badge color="red">{{ gp247_language_render('admin.alerts.count_critical', ['count' => $counts['critical']]) }}</x-gp247::badge>
            @endif
            @if (!empty($counts['warning']))
                <x-gp247::badge color="amber">{{ gp247_language_render('admin.alerts.count_warning', ['count' => $counts['warning']]) }}</x-gp247::badge>
            @endif
            @if (!empty($counts['info']))
                <x-gp247::badge color="blue">{{ gp247_language_render('admin.alerts.count_info', ['count' => $counts['info']]) }}</x-gp247::badge>
            @endif
            <x-gp247::button variant="secondary" size="sm" href="{{ gp247_route_admin('admin.system_alerts') }}" wire:navigate data-testid="admin-alerts-view-all">
                {{ gp247_language_render($worst === 'ok' ? 'admin.alerts.details' : 'admin.alerts.view_all') }}
            </x-gp247::button>
        </div>
    </x-gp247::card>
</div>
