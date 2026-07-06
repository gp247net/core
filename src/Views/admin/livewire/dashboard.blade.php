{{--
    Modern admin dashboard (ADR-002 / ADR-005 / ADR-007): renders the blocks
    configured in admin_home_layout (view/size/sort/status) inside a 12-column
    grid, each block spanning `size` columns at the xl breakpoint. Available
    blocks: welcome panel, KPI stat cards, order-trend charts (ApexCharts —
    US-AUI-005, ADR-004) and latest orders/customers. Every shop block
    degrades gracefully (Dashboard::blocks() skips any view that isn't
    registered, e.g. when the shop package isn't installed) and queries its
    own data — this layout only lays blocks out, it carries no data for them.

    ApexCharts is self-hosted and loaded via @assets only when the order_month
    block is configured (RISK-TECH-009 — not injected into the global layout).

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-005, US-LW-001
    @aidlc-adr ADR-002, ADR-004, ADR-005, ADR-007

    Variables: $blocks (array<int, array{view: string, spanClass: string}>).
--}}
@php
    $chartBlockViews = ['gp247-shop-admin::component.order_month', 'gp247-shop-admin::component.order_year'];
    $hasChartBlock = collect($blocks)->contains(fn ($block) => in_array($block['view'], $chartBlockViews, true));
@endphp
@if ($hasChartBlock)
    @assets
    <script src="{{ gp247_file('GP247/Core/AdminShell/vendor/apexcharts/apexcharts.min.js') }}"></script>
    @endassets
@endif

<div class="space-y-6">
    {{-- Edit layout shortcut --}}
    @if (Route::has('admin_home_layout.index'))
        <div class="flex justify-end">
            <x-gp247::button href="{{ gp247_route_admin('admin_home_layout.index') }}" wire:navigate size="sm" variant="secondary">
                <i class="fas fa-edit"></i> {{ gp247_language_render('admin.menu_titles.admin_home_layout') }}
            </x-gp247::button>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
        @foreach ($blocks as $block)
            <div class="w-full {{ $block['spanClass'] }}">
                @include($block['view'])
            </div>
        @endforeach
    </div>
</div>
