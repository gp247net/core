{{--
    Permissions list (ADR-002/005): search, sort, paginate, row Edit/Delete,
    bulk delete; http_uri rendered as method badges. All UI text via
    gp247_language_render (no hardcoded strings; seeded in gp247_languages).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (LengthAwarePaginator of AdminPermission).
--}}
<div>
    <x-gp247::list-toolbar :placeholder="gp247_language_render('admin.permission.search_place')"
        :selected-count="count($selected)" :bulk-confirm="gp247_language_render('admin.permission.confirm_delete')">
        <x-slot:actions>
            <x-gp247::button href="{{ gp247_route_admin('admin_permission.create') }}" wire:navigate size="sm">
                <i class="fas fa-plus"></i> {{ gp247_language_render('admin.permission.add_new') }}
            </x-gp247::button>
        </x-slot:actions>
    </x-gp247::list-toolbar>

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.no_records') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                <x-gp247::th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.permission.name') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="slug" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.permission.slug') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.permission.http_uri') }}</th>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.actions') }}</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="perm-{{ $row->id }}">
                <td class="px-4 py-3"><x-gp247::select-check :value="$row->id" /></td>
                <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $row->name }}</td>
                <td class="px-4 py-3 text-sm"><code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-700">{{ $row->slug }}</code></td>
                <td class="px-4 py-3">
                    @php $uris = $row->http_uri ? explode(',', $row->http_uri) : []; @endphp
                    <div class="flex flex-wrap gap-1">
                        @foreach (array_slice($uris, 0, 6) as $u)
                            @php [$m, $p] = array_pad(explode('::', $u, 2), 2, ''); @endphp
                            <span class="inline-flex items-center gap-1 text-xs">
                                <x-gp247::badge :color="$m === 'ANY' ? 'blue' : ($m === 'POST' ? 'amber' : 'gray')">{{ $m }}</x-gp247::badge>
                                <code class="text-gray-500 dark:text-gray-400">{{ $p }}</code>
                            </span>
                        @endforeach
                        @if (count($uris) > 6)<span class="text-xs text-gray-400">+{{ count($uris) - 6 }}</span>@endif
                    </div>
                </td>
                <td class="px-4 py-3">
                    <x-gp247::row-actions
                        :edit="gp247_route_admin('admin_permission.edit', ['id' => $row->id])"
                        :delete-id="$row->id"
                        :delete-confirm="gp247_language_render('admin.permission.confirm_delete')" />
                </td>
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
</div>
