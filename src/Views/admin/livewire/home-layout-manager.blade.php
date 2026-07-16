{{--
    Home-page layout block manager — two-panel: form (left) + list (right) on the
    ResourcePanel base (ADR-005, ADR-007, ui-tailadmin P1). UI text via
    gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002, US-UI-003
    @aidlc-adr ADR-001, ADR-005, ADR-007

    Variables: $rows (AdminHome paginator), $views (string[]), $editingId,
               $form, $sortField, $sortDir, $keyword.
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

    {{-- Left: add / edit form --}}
    <x-gp247::card :title="gp247_language_render($editingId ? 'action.edit' : 'admin.admin_home_layout.add_new_title')">
        <form wire:submit="save" class="space-y-4">

            <x-gp247::searchable-select
                model="form.view"
                :label="gp247_language_render('admin.admin_home_layout.view')"
                :options="collect($views)->map(fn ($v) => ['id' => $v, 'label' => $v])->all()"
                :error="$errors->first('form.view')"
                :required="true" />

            <div class="grid grid-cols-2 gap-4">
                <x-gp247::input type="number" min="1" max="12"
                    :label="gp247_language_render('admin.admin_home_layout.size')" name="size"
                    wire:model="form.size" :help="'1 – 12'" :error="$errors->first('form.size')" required />

                <x-gp247::input type="number" min="0"
                    :label="gp247_language_render('admin.admin_home_layout.sort')" name="sort"
                    wire:model="form.sort" :error="$errors->first('form.sort')" required />
            </div>

            <x-gp247::checkbox :label="gp247_language_render('admin.active')" wire:model="form.status" value="1" />

            <div class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_home_layout.index') }}" wire:navigate>
                    {{ gp247_language_render($editingId ? 'admin.cancel' : 'admin.reset') }}
                </x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled">
                    <i class="fas fa-save"></i> {{ gp247_language_render($editingId ? 'admin.update' : 'admin.submit') }}
                </x-gp247::button>
            </div>
        </form>
    </x-gp247::card>

    {{-- Right: list --}}
    <x-gp247::card :title="gp247_language_render('admin.admin_home_layout.list')">
        <div class="mb-3">
            <input type="search" wire:model.live.debounce.300ms="keyword"
                placeholder="{{ gp247_language_render('admin.admin_home_layout.view') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        </div>

        <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.no_records') : null">
            <x-slot:head>
                <tr>
                    <th class="cursor-pointer px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" wire:click="setSort('view')">
                        {{ gp247_language_render('admin.admin_home_layout.view') }} @if ($sortField === 'view')<span class="text-[10px]">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                    </th>
                    <th class="cursor-pointer px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" wire:click="setSort('size')">
                        {{ gp247_language_render('admin.admin_home_layout.size') }} @if ($sortField === 'size')<span class="text-[10px]">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                    </th>
                    <th class="cursor-pointer px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" wire:click="setSort('status')">
                        {{ gp247_language_render('admin.status') }} @if ($sortField === 'status')<span class="text-[10px]">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ gp247_language_render('admin.admin_home_layout.view_status') }}
                    </th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.action') }}</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                @php $viewExists = view()->exists($row->view); @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ (string) $row->id === (string) $editingId ? 'bg-blue-50 dark:bg-blue-900/30' : '' }}"
                    wire:key="home-layout-{{ $row->id }}">
                    <td class="px-4 py-3">
                        <code class="break-all text-xs text-gray-700 dark:text-gray-300">{{ $row->view }}</code>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $row->size }}/12</span>
                    </td>
                    <td class="px-4 py-3">
                        <x-gp247::badge :color="$row->status ? 'green' : 'gray'">{{ $row->status ? gp247_language_render('admin.active') : gp247_language_render('admin.inactive') }}</x-gp247::badge>
                    </td>
                    <td class="px-4 py-3">
                        @if ($viewExists)
                            <x-gp247::badge color="green"><i class="fas fa-check mr-1"></i>OK</x-gp247::badge>
                        @else
                            <x-gp247::badge color="red"><i class="fas fa-times mr-1"></i>{{ gp247_language_render('admin.display.data_not_found') }}</x-gp247::badge>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <x-gp247::button size="sm" variant="ghost" href="{{ gp247_route_admin('admin_home_layout.edit', $row->id) }}" wire:navigate><i class="fas fa-edit"></i></x-gp247::button>
                            <x-gp247::button size="sm" variant="ghost" wire:click="delete('{{ $row->id }}')" wire:confirm="{{ gp247_language_render('action.delete_confirm') }}"><i class="fas fa-trash-alt text-red-600"></i></x-gp247::button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-gp247::table>

        <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
    </x-gp247::card>
</div>
