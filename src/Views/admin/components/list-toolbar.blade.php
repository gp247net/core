{{--
    List screen toolbar (ADR-005): live search box with a loading spinner, an
    optional bulk-delete button, a page-size selector, and slots for extra
    filters (left) and primary actions such as "New …" (right). Designed for
    DataTableComponent screens (binds to keyword / selected / perPage / bulkDelete).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    @props
      - placeholder (string): search box placeholder.
      - selectedCount (int): number of selected rows (shows bulk-delete when > 0).
      - bulkConfirm (string): confirm prompt for bulk delete.
      - perPage (bool): render the page-size selector. Default true.
    @slots
      - filters: extra controls beside the search box (optional).
      - actions: right-aligned primary actions, e.g. a create button (optional).
--}}
@props([
    'placeholder' => null,
    'selectedCount' => 0,
    'bulkConfirm' => null,
    'perPage' => true,
])

@php
    $placeholder = $placeholder ?? gp247_language_render('admin.core.search');
    $bulkConfirm = $bulkConfirm ?? gp247_language_render('admin.core.confirm_delete_selected');
@endphp

<div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex flex-wrap items-center gap-2">
        <div class="relative">
            <i class="fas fa-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
            <input type="search" wire:model.live.debounce.300ms="keyword" placeholder="{{ $placeholder }}"
                class="w-64 max-w-full rounded-lg border border-gray-300 py-2 pl-9 pr-8 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
            <span wire:loading wire:target="keyword" class="absolute right-3 top-1/2 -translate-y-1/2">
                <i class="fas fa-circle-notch fa-spin text-xs text-gray-400"></i>
            </span>
        </div>

        @if ($selectedCount)
            <x-gp247::button variant="danger" size="sm" wire:click="bulkDelete" wire:confirm="{{ $bulkConfirm }}">
                <i class="fas fa-trash"></i> {{ gp247_language_render('admin.core.selected', ['count' => $selectedCount]) }}
            </x-gp247::button>
        @endif

        {{ $filters ?? '' }}
    </div>

    <div class="flex items-center gap-2">
        @if ($perPage)
            <select wire:model.live="perPage"
                class="rounded-lg border border-gray-300 px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                @foreach ([10, 20, 50, 100] as $size)
                    <option value="{{ $size }}">{{ $size }} {{ gp247_language_render('admin.core.per_page') }}</option>
                @endforeach
            </select>
        @endif

        {{ $actions ?? '' }}
    </div>
</div>
