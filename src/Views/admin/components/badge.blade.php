{{--
    GP247 TailAdmin badge / status pill (ADR-005).

    `color` is frequently a DB-driven status value, so the resulting utility
    classes are kept on the Tailwind safelist (tailwind.config.js) to survive
    purge — see ADR-004 / logical design §9.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-001
    @aidlc-adr ADR-004, ADR-005

    @props array
      - color (string): a Tailwind color name (gray, green, red, amber, blue…). Default gray.
      - solid (bool): solid fill instead of soft tint. Default false.
--}}
@props([
    'color' => 'gray',
    'solid' => false,
])

@php
    $color = preg_match('/^[a-z]+$/', $color) ? $color : 'gray';

    $classes = $solid
        ? "bg-{$color}-600 text-white"
        : "bg-{$color}-100 text-{$color}-700 dark:bg-{$color}-900 dark:text-{$color}-200";
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {$classes}"]) }}>
    {{ $slot }}
</span>
