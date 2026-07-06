{{--
    Default render for DataTableComponent (ADR-005): the shared list toolbar
    (search + bulk delete + page size), a selectable table built from the
    component's columns(), and pagination. Row values are read with data_get so
    both Eloquent models and array rows work. Concrete screens usually supply
    their own listView(); this is the fallback / embeddable shell.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002
    @aidlc-adr ADR-005

    Variables:
      - $rows (LengthAwarePaginator): current page of records.
      - $columns (array<string,string>): field => label map.
--}}
<div>
    <x-gp247::list-toolbar :selected-count="count($selected)" />

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.core.no_records') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                @foreach ($columns as $field => $label)
                    <x-gp247::th-sort :field="$field" :sort-field="$sortField" :sort-dir="$sortDir">{{ $label }}</x-gp247::th-sort>
                @endforeach
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="row-{{ data_get($row, 'id') }}">
                <td class="px-4 py-3"><x-gp247::select-check :value="data_get($row, 'id')" /></td>
                @foreach ($columns as $field => $label)
                    <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ data_get($row, $field) }}</td>
                @endforeach
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">
        {{ $rows->links('gp247-admin::partials.pagination') }}
    </div>
</div>
