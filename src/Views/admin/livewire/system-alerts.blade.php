{{--
    "System alerts" page (US-admin-shell-system-alerts-page, ADR admin-shell_system-alerts-page).
    Every check grouped by level (critical → to review → information → passed) with its technical
    detail; four filters switch groups in the browser (Alpine), "Check again" re-evaluates.
    Only Tailwind class combinations already present in the core admin bundle (rule gp247.md §3a).

    @aidlc-unit admin-shell
    @aidlc-story US-admin-shell-system-alerts-page

    Variables: $groups (array<level, AdminAlert[]>), $counts (array{all, critical, warning, ok}), $checkedAt (int|null).
--}}
@php
    use GP247\Core\AdminShell\Support\AdminAlertStyle;

    $gp247AlertTime = $checkedAt
        ? \Carbon\Carbon::createFromTimestamp($checkedAt)->setTimezone(config('app.timezone'))->format('H:i d/m/Y')
        : '';
    $gp247AlertFilters = [
        'all' => ['label' => 'admin.alerts.filter_all', 'text' => 'text-gray-500 dark:text-gray-400'],
        'critical' => ['label' => 'admin.alerts.filter_critical', 'text' => AdminAlertStyle::text('critical')],
        'warning' => ['label' => 'admin.alerts.filter_warning', 'text' => AdminAlertStyle::text('warning')],
        'ok' => ['label' => 'admin.alerts.filter_ok', 'text' => AdminAlertStyle::text('ok')],
    ];
    // Group key => [filter that shows it, heading].
    $gp247AlertGroups = [
        'critical' => ['critical', 'admin.alerts.filter_critical'],
        'warning' => ['warning', 'admin.alerts.filter_warning'],
        'info' => ['warning', 'admin.alerts.group_info'],
        'ok' => ['ok', 'admin.alerts.filter_ok'],
    ];
@endphp

<div class="space-y-5" data-testid="admin-alerts-page" x-data="{ level: 'all' }">
    <div class="flex flex-wrap items-center gap-3">
        <p class="min-w-0 flex-1 text-sm text-gray-500 dark:text-gray-400">
            <i class="fas fa-shield-alt {{ AdminAlertStyle::text($groups['critical'] ? 'critical' : 'ok') }}"></i>
            {{ gp247_language_render('admin.alerts.subtitle') }}@if ($gp247AlertTime) · {{ gp247_language_render('admin.alerts.checked_at', ['time' => $gp247AlertTime]) }}@endif
        </p>
        <x-gp247::button variant="secondary" size="sm" wire:click="recheck" wire:loading.attr="disabled" data-testid="admin-alerts-recheck">
            <i class="fas fa-sync-alt" wire:loading.class="fa-spin" wire:target="recheck"></i>
            {{ gp247_language_render('admin.alerts.recheck') }}
        </x-gp247::button>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ($gp247AlertFilters as $filter => $meta)
            <button type="button" x-on:click="level = '{{ $filter }}'"
                class="rounded-xl border bg-white px-4 py-3 text-left dark:bg-gray-800"
                x-bind:class="level === '{{ $filter }}' ? 'border-blue-500 dark:border-blue-500' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'"
                x-bind:aria-pressed="level === '{{ $filter }}' ? 'true' : 'false'"
                data-testid="admin-alerts-filter-{{ $filter }}">
                <span class="block text-sm {{ $meta['text'] }}">{{ gp247_language_render($meta['label']) }}</span>
                <span class="block text-xl font-semibold text-gray-800 dark:text-gray-100">{{ $counts[$filter] }}</span>
            </button>
        @endforeach
    </div>

    <x-gp247::card>
        @foreach ($gp247AlertGroups as $level => [$filter, $heading])
            @continue ($groups[$level] === [])
            <section class="py-2" x-show="level === 'all' || level === '{{ $filter }}'" data-testid="admin-alerts-group-{{ $level }}">
                <h3 class="py-2 text-sm font-semibold {{ AdminAlertStyle::text($level) }}">
                    {{ gp247_language_render($heading) }} ({{ count($groups[$level]) }})
                </h3>

                @foreach ($groups[$level] as $alert)
                    @if ($alert->passed())
                        <p class="flex items-center gap-3 border-t border-gray-100 py-2 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
                            data-testid="admin-alerts-passed-{{ $alert->key }}">
                            <i class="fas fa-check text-emerald-500"></i>{{ $alert->message }}
                        </p>
                        @continue
                    @endif
                    @php
                        $gp247AlertItems = array_slice($alert->items, 0, 5);
                        $gp247AlertMore = count($alert->items) - count($gp247AlertItems);
                        // Some checks already name the items in their detail; avoid printing them twice.
                        $gp247AlertShowItems = $gp247AlertItems !== [] && !str_contains($alert->detail, (string) $gp247AlertItems[0]);
                    @endphp
                    <div class="flex items-start gap-4 border-t border-gray-100 py-3 dark:border-gray-700"
                        data-testid="admin-alerts-item-{{ $alert->key }}" data-level="{{ $alert->level }}">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ AdminAlertStyle::tile($alert->level) }}">
                            <i class="{{ AdminAlertStyle::icon($alert) }}"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-800 dark:text-gray-100">{{ $alert->message }}</p>
                            @if ($alert->detail !== '' || $gp247AlertShowItems)
                                <p class="mt-0.5 break-all text-xs text-gray-500 dark:text-gray-400" data-testid="admin-alerts-detail-{{ $alert->key }}">
                                    {{ $alert->detail }}
                                    @if ($gp247AlertShowItems)
                                        — {{ implode(', ', $gp247AlertItems) }}@if ($gp247AlertMore > 0) {{ gp247_language_render('admin.alerts.and_more', ['count' => $gp247AlertMore]) }}@endif
                                    @endif
                                </p>
                            @endif
                            @if ($alert->docUrl !== '')
                                <a href="{{ $alert->docUrl }}" target="_blank" rel="noopener noreferrer"
                                    class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400"
                                    data-testid="admin-alerts-doc-{{ $alert->key }}">
                                    {{ gp247_language_render('admin.alerts.doc') }} <i class="fas fa-arrow-right"></i>
                                </a>
                            @endif
                        </div>
                        @if ($alert->fixUrl !== '')
                            <x-gp247::button variant="secondary" size="sm" href="{{ $alert->fixUrl }}" wire:navigate data-testid="admin-alerts-fix-{{ $alert->key }}">
                                {{ $alert->fixLabel }}
                            </x-gp247::button>
                        @endif
                    </div>
                @endforeach
            </section>
        @endforeach

        @foreach (['critical', 'warning', 'ok'] as $filter)
            @if ($counts[$filter] === 0)
                <p class="py-3 text-sm text-gray-500 dark:text-gray-400" x-show="level === '{{ $filter }}'">
                    {{ gp247_language_render('admin.alerts.empty_filter') }}
                </p>
            @endif
        @endforeach
    </x-gp247::card>
</div>
