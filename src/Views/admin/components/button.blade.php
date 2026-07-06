{{--
    GP247 TailAdmin button (ADR-005).

    Renders a <button> by default, or an <a> when `href` is supplied (so the same
    styling covers links and actions). Color comes from `variant`, sizing from
    `size`; any extra attributes (type, wire:click, x-on:click, disabled…) pass
    through untouched.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-001
    @aidlc-adr ADR-005

    @props array
      - variant (string): primary|secondary|success|danger|warning|ghost. Default primary.
      - size (string): sm|md|lg. Default md.
      - href (string|null): when set, renders an anchor instead of a button.
--}}
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium '
        . 'transition focus:outline-none focus:ring-2 focus:ring-offset-1 '
        . 'disabled:opacity-50 disabled:pointer-events-none';

    $variants = [
        'primary' => 'bg-blue-600 text-white hover:bg-blue-700 focus:ring-blue-500',
        'secondary' => 'bg-gray-100 text-gray-700 hover:bg-gray-200 focus:ring-gray-400 '
            . 'dark:bg-gray-700 dark:text-gray-100 dark:hover:bg-gray-600',
        'success' => 'bg-green-600 text-white hover:bg-green-700 focus:ring-green-500',
        'danger' => 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500',
        'warning' => 'bg-amber-500 text-white hover:bg-amber-600 focus:ring-amber-400',
        'ghost' => 'bg-transparent text-gray-600 hover:bg-gray-100 focus:ring-gray-300 '
            . 'dark:text-gray-300 dark:hover:bg-gray-700',
    ];

    $sizes = [
        'sm' => 'px-3 py-1.5 text-sm',
        'md' => 'px-4 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-base',
    ];

    $classes = trim($base . ' ' . ($variants[$variant] ?? $variants['primary'])
        . ' ' . ($sizes[$size] ?? $sizes['md']));
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }}>
        {{ $slot }}
    </button>
@endif
