{{--
    Users list (ADR-002/005): search, sort, paginate, row Edit/Delete (current user
    + GP247_GUARD_ADMIN protected), bulk delete; roles + status shown. UI text via
    gp247_language_render (seeded in gp247_languages).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (AdminUser paginator), $protectedIds (string[]).
--}}
<div>
    <x-gp247::list-toolbar :placeholder="gp247_language_render('admin.user.search_place')"
        :selected-count="count($selected)" :bulk-confirm="gp247_language_render('admin.user.confirm_delete')">
        <x-slot:actions>
            <x-gp247::button href="{{ gp247_route_admin('admin_user.create') }}" wire:navigate size="sm">
                <i class="fas fa-plus"></i> {{ gp247_language_render('admin.user.add_new') }}
            </x-gp247::button>
        </x-slot:actions>
    </x-gp247::list-toolbar>

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.core.no_records') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                <x-gp247::th-sort field="username" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.user.username') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.user.name') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.user.roles') }}</th>
                <x-gp247::th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.core.status') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.actions') }}</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            @php $locked = in_array((string) $row->id, $protectedIds, true); @endphp
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="user-{{ $row->id }}">
                <td class="px-4 py-3">
                    @unless ($locked)<x-gp247::select-check :value="$row->id" />@endunless
                </td>
                <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $row->username }}</td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ $row->name }}</td>
                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        @foreach ($row->roles as $r)<x-gp247::badge color="green">{{ $r->name }}</x-gp247::badge>@endforeach
                        @if ($row->roles->isEmpty())<span class="text-xs text-gray-400">—</span>@endif
                    </div>
                </td>
                <td class="px-4 py-3">
                    <x-gp247::badge :color="$row->status ? 'green' : 'gray'">{{ $row->status ? gp247_language_render('admin.core.active') : gp247_language_render('admin.core.inactive') }}</x-gp247::badge>
                </td>
                <td class="px-4 py-3">
                    <x-gp247::row-actions :locked="$locked"
                        :edit="gp247_route_admin('admin_user.edit', ['id' => $row->id])"
                        :delete-id="$row->id"
                        :delete-confirm="gp247_language_render('admin.user.confirm_delete')" />
                </td>
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
</div>
