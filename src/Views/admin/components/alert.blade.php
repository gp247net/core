{{--
    GP247 TailAdmin alert / flash message (ADR-005).

    Color-coded by `type`. When `dismissible` is true it uses Alpine to hide
    itself (Alpine ships with Livewire 4 — no extra dependency).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-001
    @aidlc-adr ADR-005

    @props array
      - type (string): info|success|warning|error. Default info.
      - title (string|null): optional bold heading line.
      - dismissible (bool): show a close button. Default false.
--}}
@props([
    'type' => 'info',
    'title' => null,
    'dismissible' => false,
])

@php
    $styles = [
        'info' => 'bg-blue-50 text-blue-800 border-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:border-blue-800',
        'success' => 'bg-green-50 text-green-800 border-green-200 dark:bg-green-900 dark:text-green-200 dark:border-green-800',
        'warning' => 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-900 dark:text-amber-200 dark:border-amber-800',
        'error' => 'bg-red-50 text-red-800 border-red-200 dark:bg-red-900 dark:text-red-200 dark:border-red-800',
    ];
    $classes = 'relative flex gap-3 rounded-lg border px-4 py-3 text-sm '
        . ($styles[$type] ?? $styles['info']);
@endphp

<div
    @if ($dismissible) x-data="{ show: true }" x-show="show" @endif
    {{ $attributes->merge(['class' => $classes]) }}
    role="alert"
>
    <div class="flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>

    @if ($dismissible)
        <button type="button" x-on:click="show = false"
            class="shrink-0 opacity-70 transition hover:opacity-100" aria-label="{{ gp247_language_render('admin.core.close') }}">
            &times;
        </button>
    @endif
</div>
