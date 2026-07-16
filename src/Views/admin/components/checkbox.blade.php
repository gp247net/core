{{--
    TailAdmin-style checkbox component (ADR-005, ui-tailadmin P2).

    Custom visual box driven by Tailwind peer modifiers — no Alpine.js required,
    fully compatible with Livewire wire:model. The real <input type="checkbox"> is
    layered transparently on top of the box (opacity-0 absolute z-10) so native
    pointer events and change/input events reach Livewire directly.

    Usage — standalone with label:
      <x-gp247::checkbox :label="gp247_language_render('admin.active')"
          wire:model="form.status" value="1" />

    Usage — bare box inside an existing <label> wrapper (list items, table cells):
      <label class="flex items-center gap-2 ...">
          <x-gp247::checkbox wire:model="form.roles" value="{{ $r->id }}" />
          <span>{{ $r->name }}</span>
      </label>

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-001
    @aidlc-adr ADR-005

    @props array
      - label (string|null): label text shown to the right of the box. When null the
        component renders only the box div (bare mode), suitable inside an outer
        <label> or table cell.
      - id (string|null): input id; derived from name if absent.
      - name (string|null): input name attribute.
      - error (string|null): validation error shown in red below the box.
      - help (string|null): muted helper text shown below the box.
--}}
@props([
    'label' => null,
    'id'    => null,
    'name'  => null,
    'error' => null,
    'help'  => null,
])
@php
    $resolvedId = $id ?? $name ?? null;
    $bare       = $label === null && $error === null && $help === null;

    // Shared Tailwind classes for the two non-input sibling divs inside the box.
    $boxClasses = 'absolute inset-0 rounded-md border-[1.25px] border-gray-300 bg-transparent '
        . 'group-hover:border-blue-500 '
        . 'peer-focus:ring-2 peer-focus:ring-blue-500 peer-focus:ring-offset-0 '
        . 'peer-checked:border-blue-600 peer-checked:bg-blue-600 '
        . 'peer-disabled:opacity-50 peer-disabled:cursor-not-allowed '
        . 'dark:border-gray-700 dark:group-hover:border-blue-500 '
        . 'dark:peer-checked:border-blue-500 dark:peer-checked:bg-blue-500';
@endphp

@php
    // Extract the inner box HTML into a variable to avoid duplication between
    // bare and labelled modes. Blade cannot call macros inline, so we use a
    // @php block that is rendered once before the @if branch.
    $boxHtml = '';
@endphp

{{-- ─── INNER BOX SNIPPET (rendered via @include trick) ─── --}}
@if ($bare)
    {{-- Bare mode: just the visual box, no outer wrapper. --}}
    <div class="relative h-5 w-5 shrink-0 group">
        <input
            type="checkbox"
            @if ($resolvedId) id="{{ $resolvedId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
            {{ $attributes->except(['class', 'id', 'name', 'type']) }}
        />
        <div class="{{ $boxClasses }}"></div>
        <div class="pointer-events-none absolute inset-0 z-0 flex items-center justify-center opacity-0 peer-checked:opacity-100">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M11.6666 3.5L5.24992 9.91667L2.33325 7"
                      stroke="white" stroke-width="1.94437"
                      stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>
    </div>
@else
    {{-- Full mode: label wrapper + box + optional error / help text. --}}
    <div class="space-y-1">
        <label
            @if ($resolvedId) for="{{ $resolvedId }}" @endif
            class="flex cursor-pointer select-none items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-400">

            <div class="relative h-5 w-5 shrink-0 group">
                <input
                    type="checkbox"
                    @if ($resolvedId) id="{{ $resolvedId }}" @endif
                    @if ($name) name="{{ $name }}" @endif
                    class="peer absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                    {{ $attributes->except(['class', 'id', 'name', 'type']) }}
                />
                <div class="{{ $boxClasses }}"></div>
                <div class="pointer-events-none absolute inset-0 z-0 flex items-center justify-center opacity-0 peer-checked:opacity-100">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11.6666 3.5L5.24992 9.91667L2.33325 7"
                              stroke="white" stroke-width="1.94437"
                              stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </div>

            @if ($label)
                <span>{{ $label }}</span>
            @endif
        </label>

        @if ($error)
            <p class="text-sm text-red-600 dark:text-red-400">{{ $error }}</p>
        @elseif ($help)
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $help }}</p>
        @endif
    </div>
@endif
