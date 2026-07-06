{{--
    Dashboard bar chart — ApexCharts (US-AUI-005, ADR-004). Replaces the interim
    inline-SVG implementation. ApexCharts is self-hosted at
    public/GP247/Core/AdminShell/vendor/apexcharts/ and loaded via @assets in the
    parent dashboard view. The Alpine factory gp247DashboardChart(config) is
    registered in admin.js.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-005
    @aidlc-adr ADR-004

    Variables:
      - $series (array<int, array{label:string, value:float}>): data points.
      - $color  (string): bar fill hex (e.g. #3b82f6). Default: #3b82f6.
      - $name   (string): series name shown in tooltip. Default: ''.
--}}
@php
    $chartConfig = json_encode([
        'labels' => array_column($series ?? [], 'label'),
        'values' => array_column($series ?? [], 'value'),
        'color'  => $color ?? '#3b82f6',
        'name'   => $name  ?? '',
    ], JSON_THROW_ON_ERROR);
@endphp
<div wire:ignore
     x-data="gp247DashboardChart({{ $chartConfig }})"
     x-init="init()"
     class="h-56 w-full min-w-0 overflow-hidden">
</div>
