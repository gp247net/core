{{--
    Roles list (ADR-002/005): search, sort, paginate, row Edit/Delete (guard-aware
    GP247_GUARD_ROLES), bulk delete; assigned permissions shown as badges. UI text
    via gp247_language_render (seeded in gp247_languages).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (AdminRole paginator), $guardedIds (int[]).
--}}
<div>
    <x-gp247::list-toolbar :placeholder="gp247_language_render('admin.role.search_place')"
        :selected-count="count($selected)" :bulk-confirm="gp247_language_render('admin.role.confirm_delete')">
        <x-slot:actions>
            <x-gp247::button href="{{ gp247_route_admin('admin_role.create') }}" wire:navigate size="sm">
                <i class="fas fa-plus"></i> {{ gp247_language_render('admin.role.add_new') }}
            </x-gp247::button>
        </x-slot:actions>
    </x-gp247::list-toolbar>

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.core.no_records') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                <x-gp247::th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.role.name') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="slug" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.role.slug') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.role.permissions') }}</th>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.actions') }}</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            @php $guarded = in_array((int) $row->id, $guardedIds, true); @endphp
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="role-{{ $row->id }}">
                <td class="px-4 py-3">
                    @unless ($guarded)<x-gp247::select-check :value="$row->id" />@endunless
                </td>
                <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">
                    {{ $row->name }}
                    @if ($guarded)<x-gp247::badge color="slate" class="ml-1">{{ gp247_language_render('admin.core.built_in') }}</x-gp247::badge>@endif
                </td>
                <td class="px-4 py-3 text-sm"><code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-700">{{ $row->slug }}</code></td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        @foreach ($row->permissions->take(8) as $p)
                            <x-gp247::badge color="green">{{ $p->name }}</x-gp247::badge>
                        @endforeach
                        @if ($row->permissions->count() > 8)<span class="text-xs text-gray-400">+{{ $row->permissions->count() - 8 }}</span>@endif
                        @if ($row->permissions->isEmpty())<span class="text-xs text-gray-400">—</span>@endif
                    </div>
                </td>
                <td class="px-4 py-3">
                    <x-gp247::row-actions :locked="$guarded"
                        :edit="$guarded ? null : gp247_route_admin('admin_role.edit', ['id' => $row->id])"
                        :delete-id="$row->id"
                        :delete-confirm="gp247_language_render('admin.role.confirm_delete')" />
                </td>
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
</div>
