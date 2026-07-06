{{--
    Home-page layout blocks list (ADR-002/005): search by view, sort, paginate,
    row Edit/Delete + bulk delete, on/off status badge and a "view exists" health
    badge. UI text via gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (AdminHome paginator).
--}}
<div>
    <x-gp247::list-toolbar :placeholder="gp247_language_render('admin.admin_home_layout.view')"
        :selected-count="count($selected)" :bulk-confirm="gp247_language_render('action.delete_confirm')">
        <x-slot:actions>
            <x-gp247::button href="{{ gp247_route_admin('admin_home_layout.create') }}" wire:navigate size="sm">
                <i class="fas fa-plus"></i> {{ gp247_language_render('admin.admin_home_layout.add_new_title') }}
            </x-gp247::button>
        </x-slot:actions>
    </x-gp247::list-toolbar>

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.core.no_records') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                <x-gp247::th-sort field="view" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.admin_home_layout.view') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="size" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.admin_home_layout.size') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="sort" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.admin_home_layout.sort') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.admin_home_layout.status') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.admin_home_layout.view_status') }}</th>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.actions') }}</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            @php $viewExists = view()->exists($row->view); @endphp
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="home-{{ $row->id }}">
                <td class="px-4 py-3"><x-gp247::select-check :value="$row->id" /></td>
                <td class="px-4 py-3">
                    <code class="break-all text-xs text-gray-700 dark:text-gray-300">{{ $row->view }}</code>
                </td>
                <td class="px-4 py-3">
                    <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $row->size }}/12</span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->sort }}</td>
                <td class="px-4 py-3">
                    <x-gp247::badge :color="$row->status ? 'green' : 'gray'">{{ $row->status ? gp247_language_render('admin.core.active') : gp247_language_render('admin.core.inactive') }}</x-gp247::badge>
                </td>
                <td class="px-4 py-3">
                    @if ($viewExists)
                        <x-gp247::badge color="green"><i class="fas fa-check mr-1"></i>OK</x-gp247::badge>
                    @else
                        <x-gp247::badge color="red"><i class="fas fa-times mr-1"></i>{{ gp247_language_render('display.data_not_found') }}</x-gp247::badge>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <x-gp247::row-actions
                        :edit="gp247_route_admin('admin_home_layout.edit', ['id' => $row->id])"
                        :delete-id="$row->id"
                        :delete-confirm="gp247_language_render('action.delete_confirm')" />
                </td>
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
</div>
