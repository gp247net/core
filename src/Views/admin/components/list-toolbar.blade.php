{{--
    List screen toolbar (ADR-005): live search box with a loading spinner, an
    optional bulk-delete button, a page-size selector, and slots for extra
    filters (left) and primary actions such as "New …" (right). Designed for
    DataTableComponent screens (binds to keyword / selected / perPage / bulkDelete).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    @props
      - placeholder (string): search box placeholder (pass $this->searchPlaceholder()).
      - hint (string): hint line naming the searched columns (pass $this->searchHint()); '' → none.
      - selectedCount (int): number of selected rows (shows bulk-delete when > 0).
      - bulkConfirm (string): confirm prompt for bulk delete.
      - perPage (bool): render the page-size selector. Default true.
    @slots
      - filters: extra controls beside the search box (optional).
      - actions: right-aligned primary actions, e.g. a create button (optional).
--}}
@props([
    'placeholder' => null,
    'hint' => '',
    'selectedCount' => 0,
    'bulkConfirm' => null,
    'perPage' => true,
])

@php
    $placeholder = $placeholder ?? gp247_language_render('admin.search');
    $bulkConfirm = $bulkConfirm ?? gp247_language_render('admin.confirm_delete_selected');
@endphp

{{-- WHY: items-start keeps the actions level with the search box when its hint line adds height below it. --}}
<div class="mb-4 flex flex-col items-start gap-3 sm:flex-row sm:justify-between">
    <div class="flex flex-wrap items-start gap-2">
        <x-gp247::search-box :placeholder="$placeholder" :hint="$hint" class="w-64 max-w-full" />

        @if ($selectedCount)
            <x-gp247::button variant="danger" size="sm" wire:click="bulkDelete" wire:confirm="{{ $bulkConfirm }}">
                <i class="fas fa-trash"></i> {{ gp247_language_render('admin.selected', ['count' => $selectedCount]) }}
            </x-gp247::button>
        @endif

        {{ $filters ?? '' }}
    </div>

    <div class="flex items-center gap-2">
        @if ($perPage)
            <select wire:model.live="perPage"
                class="rounded-lg border border-gray-300 px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                @foreach ([10, 20, 50, 100] as $size)
                    <option value="{{ $size }}">{{ $size }} {{ gp247_language_render('admin.per_page') }}</option>
                @endforeach
            </select>
        @endif

        {{ $actions ?? '' }}
    </div>
</div>
