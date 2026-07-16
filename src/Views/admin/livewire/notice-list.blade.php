{{--
    Admin notifications list (US-AUI-008, ADR-002/005): owner-scoped notices with
    live search, sort, paginate, mark-all-read, row + bulk delete. Icon by type
    mirrors the header bell block; content via gp247_content_render. UI text via
    gp247_language_render (seeded in gp247_languages).

    @aidlc-unit admin-shell
    @aidlc-story US-AUI-008
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (AdminNotice paginator), $sortField, $sortDir, $selected.
--}}
<div>
    <x-gp247::list-toolbar :placeholder="gp247_language_render('admin.notice.content')"
        :selected-count="count($selected)" :bulk-confirm="gp247_language_render('action.delete_confirm')">
        <x-slot:actions>
            <x-gp247::button variant="secondary" size="sm" wire:click="markRead">
                <i class="fas fa-check-double"></i> {{ gp247_language_render('admin.notice.mark_read') }}
            </x-gp247::button>
        </x-slot:actions>
    </x-gp247::list-toolbar>

    <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.notice.empty') : null">
        <x-slot:head>
            <tr>
                <th class="w-10 px-4 py-3"></th>
                <x-gp247::th-sort field="type" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.notice.type') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.notice.content') }}</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.notice.admin_created') }}</th>
                <x-gp247::th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('order.status') }}</x-gp247::th-sort>
                <x-gp247::th-sort field="created_at" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.notice.created_at') }}</x-gp247::th-sort>
                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.actions') }}</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ $row->status ? '' : 'bg-blue-50/60 dark:bg-blue-900/10' }}" wire:key="notice-{{ $row->id }}">
                <td class="px-4 py-3"><x-gp247::select-check :value="$row->id" /></td>
                <td class="px-4 py-3 text-sm">
                    <span class="inline-flex items-center gap-2 text-gray-700 dark:text-gray-200">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                            @if (in_array($row->type, ['gp247_order_created', 'gp247_order_success', 'gp247_order_update_status']))
                                <i class="fas fa-cart-plus text-xs"></i>
                            @elseif (in_array($row->type, ['gp247_customer_created']))
                                <i class="fas fa-users text-xs"></i>
                            @else
                                <i class="far fa-bell text-xs"></i>
                            @endif
                        </span>
                        <span>
                            <span class="block">{{ $row->type }}</span>
                            @if ($row->type_id)
                                <span class="block text-xs text-gray-400">#{{ $row->type_id }}</span>
                            @endif
                        </span>
                    </span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">{{ gp247_content_render($row->content) }}</td>
                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->admin->name ?? $row->admin_created }}</td>
                <td class="px-4 py-3">
                    @if ($row->status)
                        <x-gp247::badge color="gray">{{ gp247_language_render('admin.notice.read') }}</x-gp247::badge>
                    @else
                        <x-gp247::badge color="blue">{{ gp247_language_render('admin.notice.unread') }}</x-gp247::badge>
                    @endif
                </td>
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
