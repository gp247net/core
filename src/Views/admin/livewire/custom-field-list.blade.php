{{--
    Custom fields list (ADR-002/005): search, sort, paginate, row Edit/Delete, bulk.
    UI text via gp247_language_render (seeded in gp247_languages).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (AdminCustomField paginator), $tables (entity type => label).
--}}
<div>
    <x-gp247::list-toolbar :placeholder="gp247_language_render('admin.custom_field.search_place')"
        :selected-count="count($selected)" :bulk-confirm="gp247_language_render('admin.custom_field.confirm_delete')">
        <x-slot:actions>
            <x-gp247::button href="{{ gp247_route_admin('admin_custom_field.create') }}" wire:navigate size="sm">
                <i class="fas fa-plus"></i> {{ gp247_language_render('admin.custom_field.add_new') }}
            </x-gp247::button>
        </x-slot:actions>
    </x-gp247::list-toolbar>

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.no_records') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                <x-gp247::th-sort field="code" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.custom_field.code') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.custom_field.name') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.custom_field.type') }}</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.custom_field.required') }}</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.custom_field.status') }}</th>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.actions') }}</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="cf-{{ $row->id }}">
                <td class="px-4 py-3"><x-gp247::select-check :value="$row->id" /></td>
                <td class="px-4 py-3 text-sm"><code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-700">{{ $row->code }}</code></td>
                <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $row->name }}</td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $tables[$row->type] ?? $row->type }}</td>
                <td class="px-4 py-3"><x-gp247::badge :color="$row->required ? 'amber' : 'gray'">{{ $row->required ? gp247_language_render('admin.yes') : gp247_language_render('admin.no') }}</x-gp247::badge></td>
                <td class="px-4 py-3"><x-gp247::badge :color="$row->status ? 'green' : 'gray'">{{ $row->status ? gp247_language_render('admin.on') : gp247_language_render('admin.off') }}</x-gp247::badge></td>
                <td class="px-4 py-3">
                    <x-gp247::row-actions
                        :edit="gp247_route_admin('admin_custom_field.edit', ['id' => $row->id])"
                        :delete-id="$row->id"
                        :delete-confirm="gp247_language_render('admin.custom_field.confirm_delete')" />
                </td>
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
</div>
