{{--
    User manager — two-panel: add/edit form (left) + live list (right).
    Uses ResourcePanel base: editRow/delete wired inline. Current user + guarded
    admins are protected. Avatar via media-input (LFM). Role assignment.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003
    @aidlc-adr ADR-005, ADR-007

    Variables: $rows (paginator), $protectedIds (string[]), $roleOptions, $editingId, $form.
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

    {{-- Left: add / edit form --}}
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.user.form_edit' : 'admin.user.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.user.name')" name="name"
                wire:model="form.name" :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.user.username')" name="username"
                wire:model="form.username" :help="gp247_language_render('admin.user.username_help')"
                :error="$errors->first('form.username')" required />

            <x-gp247::input :label="gp247_language_render('admin.user.email')" name="email" type="email"
                wire:model="form.email" :error="$errors->first('form.email')" required />

            <x-gp247::input :label="gp247_language_render('admin.user.password')" name="password" type="password"
                wire:model="form.password"
                :help="$editingId ? gp247_language_render('admin.user.password_keep') : null"
                :error="$errors->first('form.password')" :required="! $editingId" />

            <x-gp247::media-input :label="gp247_language_render('admin.user.avatar')" name="avatar" type="avatar"
                wire:model="form.avatar" :value="$form['avatar'] ?? null"
                :error="$errors->first('form.avatar')" />

            <x-gp247::checkbox :label="gp247_language_render('admin.core.active')" wire:model="form.status" value="1" />

            <x-gp247::searchable-select
                model="form.roles"
                :options="$roleOptions"
                :multiple="true"
                :label="gp247_language_render('admin.user.roles') . ' (' . gp247_language_render('admin.core.selected', ['count' => count($form['roles'])]) . ')'"
                :help="gp247_language_render('admin.user.role_override_note')" />

            <div class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                @if ($editingId)
                    <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_user.index') }}" wire:navigate>
                        {{ gp247_language_render('admin.core.cancel') }}
                    </x-gp247::button>
                @else
                    <x-gp247::button variant="secondary" wire:click="resetForm" type="button">
                        {{ gp247_language_render('admin.core.reset') }}
                    </x-gp247::button>
                @endif
                <x-gp247::button type="submit" wire:loading.attr="disabled">
                    <i class="fas fa-save"></i>
                    {{ gp247_language_render($editingId ? 'admin.core.update' : 'admin.core.submit') }}
                </x-gp247::button>
            </div>
        </form>
    </x-gp247::card>

    {{-- Right: list --}}
    <x-gp247::card :title="gp247_language_render('admin.user.title')">
        <div class="mb-3">
            <input type="search" wire:model.live.debounce.300ms="keyword"
                placeholder="{{ gp247_language_render('admin.user.search_place') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        </div>

        <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.core.no_records') : null">
            <x-slot:head>
                <tr>
                    <x-gp247::th-sort field="username" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.user.username') }}</x-gp247::th-sort>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.user.roles') }}</th>
                    <x-gp247::th-sort field="status" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.core.status') }}</x-gp247::th-sort>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.actions') }}</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                @php $locked = in_array((string) $row->id, $protectedIds, true); @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ (string) $row->id === (string) $editingId ? 'bg-blue-50 dark:bg-blue-900/30' : '' }}"
                    wire:key="user-{{ $row->id }}">
                    <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $row->username }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            @foreach ($row->roles as $r)
                                <x-gp247::badge color="green">{{ $r->name }}</x-gp247::badge>
                            @endforeach
                            @if ($row->roles->isEmpty())<span class="text-xs text-gray-400">—</span>@endif
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <x-gp247::badge :color="$row->status ? 'green' : 'gray'">
                            {{ $row->status ? gp247_language_render('admin.core.active') : gp247_language_render('admin.core.inactive') }}
                        </x-gp247::badge>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            @unless ($locked)
                                <x-gp247::button size="sm" variant="ghost" href="{{ gp247_route_admin('admin_user.edit', $row->id) }}" wire:navigate>
                                    <i class="fas fa-edit"></i>
                                </x-gp247::button>
                                <x-gp247::button size="sm" variant="ghost"
                                    wire:click="delete({{ $row->id }})"
                                    wire:confirm="{{ gp247_language_render('admin.user.confirm_delete') }}">
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
