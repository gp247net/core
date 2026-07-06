{{--
    Sortable table header cell (ADR-005). Emits wire:click="setSort(field)" and
    renders the active sort arrow, or a faded hint icon to signal sortability.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    @props
      - field (string): column key passed to setSort().
      - sortField (string): the component's active sort column.
      - sortDir (string): "asc" | "desc".
      - align (string): text alignment ("left" | "right" | "center"). Default "left".
    @slot default: header label.
--}}
@props([
    'field',
    'sortField' => '',
    'sortDir' => 'asc',
    'align' => 'left',
])

<th scope="col" wire:click="setSort('{{ $field }}')"
    {{ $attributes->merge(['class' => 'group cursor-pointer select-none whitespace-nowrap px-4 py-3 text-' . $align . ' text-xs font-semibold uppercase tracking-wide text-gray-500 transition-colors hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200']) }}>
    <span class="inline-flex items-center gap-1">
        {{ $slot }}
        @if ($sortField === $field)
            <i class="fas fa-sort-{{ $sortDir === 'asc' ? 'up' : 'down' }} text-[10px] text-blue-500"></i>
        @else
            {{-- WHY: faded icon advertises the column is sortable before any click. --}}
            <i class="fas fa-sort text-[10px] text-gray-300 transition-colors group-hover:text-gray-400 dark:text-gray-600"></i>
        @endif
    </span>
</th>
