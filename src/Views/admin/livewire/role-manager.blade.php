{{--
    Role manager — two-panel: add/edit form (left) + live list (right).
    Uses ResourcePanel base: editRow/delete wired inline, guarded roles read-only.
    Permission + user pickers with Alpine client-side filter.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003
    @aidlc-adr ADR-005, ADR-007

    Variables: $rows (paginator), $guardedIds (int[]), $permOptions, $userOptions, $editingId, $form.
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

    {{-- Left: add / edit form --}}
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.role.form_edit' : 'admin.role.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.role.name')" name="name"
                wire:model="form.name" :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.role.slug')" name="slug"
                wire:model="form.slug" :help="gp247_language_render('admin.role.slug_help')"
                :error="$errors->first('form.slug')" required />

            <x-gp247::searchable-select
                model="form.permissions"
                :options="$permOptions"
                :multiple="true"
                :label="gp247_language_render('admin.role.permissions') . ' (' . gp247_language_render('admin.selected', ['count' => count($form['permissions'])]) . ')'"
                :placeholder="gp247_language_render('admin.role.filter_permissions')" />

            <x-gp247::searchable-select
                model="form.administrators"
                :options="$userOptions"
                :multiple="true"
                :label="gp247_language_render('admin.role.users_in_role') . ' (' . gp247_language_render('admin.selected', ['count' => count($form['administrators'])]) . ')'"
                :placeholder="gp247_language_render('admin.role.filter_users')" />

            <div class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                @if ($editingId)
                    <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_role.index') }}" wire:navigate>
                        {{ gp247_language_render('admin.cancel') }}
                    </x-gp247::button>
                @else
                    <x-gp247::button variant="secondary" wire:click="resetForm" type="button">
                        {{ gp247_language_render('admin.reset') }}
                    </x-gp247::button>
                @endif
                <x-gp247::button type="submit" wire:loading.attr="disabled">
                    <i class="fas fa-save"></i>
                    {{ gp247_language_render($editingId ? 'admin.update' : 'admin.submit') }}
                </x-gp247::button>
            </div>
        </form>
    </x-gp247::card>

    {{-- Right: list --}}
    <x-gp247::card :title="gp247_language_render('admin.role.title')">
        <div class="mb-3">
            <input type="search" wire:model.live.debounce.300ms="keyword"
                placeholder="{{ gp247_language_render('admin.role.search_place') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        </div>

        <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.no_records') : null">
            <x-slot:head>
                <tr>
                    <x-gp247::th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.role.name') }}</x-gp247::th-sort>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.role.permissions') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.actions') }}</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                @php $guarded = in_array((int) $row->id, $guardedIds, true); @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ (string) $row->id === (string) $editingId ? 'bg-blue-50 dark:bg-blue-900/30' : '' }}"
                    wire:key="role-{{ $row->id }}">
                    <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">
                        {{ $row->name }}
                        @if ($guarded)
                            <x-gp247::badge color="slate" class="ml-1">{{ gp247_language_render('admin.built_in') }}</x-gp247::badge>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($row->permissions->take(4) as $p)
                                <x-gp247::badge color="green">{{ $p->name }}</x-gp247::badge>
                            @endforeach
                            @if ($row->permissions->count() > 4)
                                <span class="text-xs text-gray-400">+{{ $row->permissions->count() - 4 }}</span>
                            @endif
                            @if ($row->permissions->isEmpty())
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            @unless ($guarded)
                                <x-gp247::button size="sm" variant="ghost" href="{{ gp247_route_admin('admin_role.edit', $row->id) }}" wire:navigate>
                                    <i class="fas fa-edit"></i>
                                </x-gp247::button>
                                <x-gp247::button size="sm" variant="ghost"
                                    wire:click="delete('{{ $row->id }}')"
                                    wire:confirm="{{ gp247_language_render('admin.role.confirm_delete') }}">
                                    <i class="fas fa-trash-alt text-red-600"></i>
                                </x-gp247::button>
                            @else
                                <span class="px-2 text-xs text-gray-400"><i class="fas fa-lock"></i></span>
                            @endunless
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-gp247::table>

        <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
    </x-gp247::card>

</div>
