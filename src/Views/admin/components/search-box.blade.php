{{--
    Search box of an admin list (ADR-005, amendment 2026-11-07): one
    `type="search"` input bound with `wire:model.live.debounce.300ms` (the exact
    selector E2E page objects use), a loading spinner, a clear button and an
    optional hint line naming the searched columns. The box keeps the raw value the
    admin types; the component's query trims it (ListSearch::searchKeyword()).

    Usage inside a Livewire list view:
        <x-gp247::search-box :placeholder="$this->searchPlaceholder()" :hint="$this->searchHint()" />

    @aidlc-unit admin-shell
    @aidlc-story US-admin-shell-list-search-scope-hint
    @aidlc-adr ADR-005

    @props
      - placeholder (string|null): placeholder; null → generic "Search…".
      - hint (string): hint line under the box ("Searching in: …"); '' → no line.
      - inputClass (string): extra classes for the input (e.g. a smaller height).
    @attributes merged on the wrapper (e.g. class="w-64").
--}}
@props([
    'placeholder' => null,
    'hint' => '',
    'inputClass' => '',
])

@php
    $placeholder = $placeholder ?? gp247_language_render('admin.search');
@endphp

@once
    {{-- WHY: Chromium/Safari draw their own clear "x" on type="search"; hide it so
         only this component's clear button shows (no Tailwind build needed). --}}
    <style>.gp247-search-box__input::-webkit-search-cancel-button{-webkit-appearance:none;appearance:none}</style>
@endonce

{{-- WHY: default to full width only when the caller sets no width of its own (w-full + w-64 would clash). --}}
<div {{ $attributes->merge(['class' => $attributes->has('class') ? '' : 'w-full']) }}>
    <div class="relative">
        <i class="fas fa-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
        <input type="search" wire:model.live.debounce.300ms="keyword" placeholder="{{ $placeholder }}"
            data-testid="admin-shell-list-search" @if ($hint !== '') title="{{ $hint }}" @endif
            class="gp247-search-box__input w-full rounded-lg border border-gray-300 py-2 pl-9 pr-8 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 {{ $inputClass }}">
        <span wire:loading wire:target="keyword" class="absolute right-3 top-1/2 -translate-y-1/2">
            <i class="fas fa-circle-notch fa-spin text-xs text-gray-400"></i>
        </span>
        <button type="button" wire:click="$set('keyword', '')" wire:loading.remove wire:target="keyword"
            x-cloak x-show="$wire.keyword !== ''"
            data-testid="admin-shell-list-search-clear"
            title="{{ gp247_language_render('admin.search.clear') }}" aria-label="{{ gp247_language_render('admin.search.clear') }}"
            class="absolute right-3 top-1/2 -translate-y-1/2 rounded p-1.5 text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
    @if ($hint !== '')
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-testid="admin-shell-list-search-hint">{{ $hint }}</p>
    @endif
</div>
