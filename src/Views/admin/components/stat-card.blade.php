{{--
    GP247 TailAdmin KPI stat tile (ADR-005/007).

    A single metric card: colored icon badge, label, big value and an optional
    "view more" link. Extracted so dashboard blocks (core or shop) render a
    uniform stats row instead of re-implementing the card markup per block.

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-005
    @aidlc-adr ADR-005, ADR-007

    @props array
      - label (string): metric name.
      - value (int|string): pre-formatted metric value.
      - icon (string): Font Awesome icon classes.
      - color (string): palette key below (emerald|sky|amber).
      - url (string|null): optional "view more" link.
--}}
@props([
    'label',
    'value',
    'icon' => 'fas fa-chart-simple',
    'color' => 'emerald',
    'url' => null,
])

@php
    // WHY: bg/text-{color}-{shade} with dark/hover variants are safelisted in
    // tailwind.config.js (RISK-TECH-002) so this stays a plain lookup — no new
    // class strings need to exist literally in any scanned Blade file.
    $palette = [
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'sky' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/10 dark:text-sky-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800']) }}>
    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl text-xl {{ $palette[$color] ?? $palette['emerald'] }}">
        <i class="{{ $icon }}"></i>
    </span>
    <div class="min-w-0 flex-1">
        <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
        <p class="mt-0.5 text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $value }}</p>
        @if ($url)
            <a href="{{ $url }}" class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:underline dark:text-blue-400">
                {{ gp247_language_render('action.view_more') }}
                <i class="fas fa-arrow-right text-[10px]"></i>
            </a>
        @endif
    </div>
</div>
