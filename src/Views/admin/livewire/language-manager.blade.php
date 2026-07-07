{{--
    Two-panel language manager (ADR-005): add/edit form (left) + list (right) on
    one page, matching the legacy layout. Edit loads a row into the form; save and
    delete refresh the list inline. UI text via gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-002, US-UI-007
    @aidlc-adr ADR-002, ADR-005

    Variables: $rows (LengthAwarePaginator of AdminLanguage).
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- Left: add / edit form --}}
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.language.edit' : 'admin.language.add')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.core.name')" name="name" wire:model="form.name"
                :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.core.code')" name="code" wire:model="form.code"
                :help="gp247_language_render('admin.language.code_help')" :error="$errors->first('form.code')" required />

            <x-gp247::media-input :label="gp247_language_render('admin.core.icon')" name="icon" type="language"
                wire:model="form.icon" :value="$form['icon'] ?? null"
                :error="$errors->first('form.icon')" required />

            <x-gp247::input :label="gp247_language_render('admin.core.sort')" name="sort" type="number" wire:model="form.sort"
                :error="$errors->first('form.sort')" />

            <div class="flex flex-col gap-3">
                <x-gp247::checkbox :label="gp247_language_render('admin.language.rtl')" wire:model="form.rtl" value="1" />
                <x-gp247::checkbox :label="gp247_language_render('admin.core.active')" wire:model="form.status" value="1" />
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_language.index') }}" wire:navigate>{{ gp247_language_render($editingId ? 'admin.core.cancel' : 'admin.core.reset') }}</x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled">
                    <i class="fas fa-save"></i> {{ gp247_language_render($editingId ? 'admin.core.update' : 'admin.core.submit') }}
                </x-gp247::button>
            </div>
        </form>
    </x-gp247::card>

    {{-- Right: list --}}
    <x-gp247::card :title="gp247_language_render('admin.language.list_title')">
        <div class="mb-3">
            <input type="search" wire:model.live.debounce.300ms="keyword" placeholder="{{ gp247_language_render('admin.language.search_place') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        </div>

        <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.language.no_found') : null">
            <x-slot:head>
                <tr>
                    @foreach (['name' => 'admin.core.name', 'code' => 'admin.core.code'] as $f => $labelKey)
                        <th class="cursor-pointer px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" wire:click="setSort('{{ $f }}')">
                            {{ gp247_language_render($labelKey) }} @if ($sortField === $f)<span class="text-[10px]">{{ $sortDir === 'asc' ? '▲' : '▼' }}</span>@endif
                        </th>
                    @endforeach
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.icon') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.status') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.action') }}</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                @php $guarded = in_array((int) $row->id, $this->guardedIds(), true); @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ (string) $row->id === (string) $editingId ? 'bg-blue-50 dark:bg-blue-900/30' : '' }}" wire:key="lang-{{ $row->id }}">
                    <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $row->name }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{{ $row->code }}</td>
                    <td class="px-4 py-3">@if ($row->icon)<img src="{{ gp247_file($row->icon) }}" alt="{{ $row->name }}" class="h-6 w-auto rounded">@endif</td>
                    <td class="px-4 py-3"><x-gp247::badge :color="$row->status ? 'green' : 'gray'">{{ $row->status ? gp247_language_render('admin.core.on') : gp247_language_render('admin.core.off') }}</x-gp247::badge></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <x-gp247::button size="sm" variant="ghost" href="{{ gp247_route_admin('admin_language.edit', $row->id) }}" wire:navigate><i class="fas fa-edit"></i></x-gp247::button>
                            @unless ($guarded)
                                <x-gp247::button size="sm" variant="ghost" wire:click="delete('{{ $row->id }}')" wire:confirm="{{ gp247_language_render('admin.language.confirm_delete') }}">
                                    <i class="fas fa-trash-alt text-red-600"></i>
                                </x-gp247::button>
                            @endunless
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-gp247::table>

        <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
    </x-gp247::card>
</div>
