{{--
    Admin operation-log list (ADR-002/005): search IP/Path, sort, paginate, row +
    bulk delete (read-only — no edit). Method shown as a colored badge. UI text via
    gp247_language_render (seeded in gp247_languages).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (AdminLog paginator), $methodColors (method => color).
--}}
<div>
    <x-gp247::list-toolbar :placeholder="gp247_language_render('admin.log.ip') . ', ' . gp247_language_render('admin.log.path')"
        :selected-count="count($selected)" :bulk-confirm="gp247_language_render('action.delete_confirm')" />

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.no_records') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                <x-gp247::th-sort field="user_id" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.log.user') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="method" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.log.method') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="path" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.log.path') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="ip" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.log.ip') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.log.user_agent') }}</th>
                <x-gp247::th-sort field="created_at" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.log.created_at') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.actions') }}</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50" wire:key="log-{{ $row->id }}">
                <td class="px-4 py-3"><x-gp247::select-check :value="$row->id" /></td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ $row->user->name ?? 'N/A' }}</span>
                    <span class="block text-xs text-gray-400">#{{ $row->user_id }}</span>
                </td>
                <td class="px-4 py-3">
                    <x-gp247::badge :color="$methodColors[$row->method] ?? 'gray'">{{ $row->method }}</x-gp247::badge>
                </td>
                <td class="px-4 py-3">
                    <code class="break-all text-xs text-gray-600 dark:text-gray-300">{{ $row->path }}</code>
                </td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->ip }}</td>
                <td class="max-w-xs truncate px-4 py-3 text-xs text-gray-400 dark:text-gray-500" title="{{ $row->user_agent }}">{{ $row->user_agent }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500 dark:text-gray-400">{{ $row->created_at }}</td>
                <td class="px-4 py-3">
                    <x-gp247::row-actions :delete-id="$row->id"
                        :delete-confirm="gp247_language_render('action.delete_confirm')" />
                </td>
            </tr>
        @endforeach
    </x-gp247::table>

    <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
</div>
