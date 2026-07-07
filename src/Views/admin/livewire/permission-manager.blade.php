{{--
    Permission manager — two-panel: add/edit form (left) + live list (right).
    Uses ResourcePanel base: editRow/delete wired inline, no full-page navigation.
    URI picker grouped by route prefix with Alpine client-side filter.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003
    @aidlc-adr ADR-005, ADR-007

    Variables: $rows (paginator), $routeOptions (flat array for searchable-select), $editingId, $form.
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

    {{-- Left: add / edit form --}}
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.permission.form_edit' : 'admin.permission.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.permission.name')" name="name"
                wire:model="form.name" :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.permission.slug')" name="slug"
                wire:model="form.slug" :help="gp247_language_render('admin.permission.slug_help')"
                :error="$errors->first('form.slug')" required />

            <x-gp247::searchable-select
                model="form.http_uri"
                :options="$routeOptions"
                :multiple="true"
                :label="gp247_language_render('admin.permission.allowed_routes') . ' (' . gp247_language_render('admin.core.selected', ['count' => count($form['http_uri'])]) . ')'"
                :placeholder="gp247_language_render('admin.permission.filter_routes')"
                :error="$errors->first('form.http_uri')" />

            <div class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                @if ($editingId)
                    <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_permission.index') }}" wire:navigate>
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
    <x-gp247::card :title="gp247_language_render('admin.permission.title')">
        <div class="mb-3">
            <input type="search" wire:model.live.debounce.300ms="keyword"
                placeholder="{{ gp247_language_render('admin.permission.search_place') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        </div>

        <x-gp247::table :empty="$rows->isEmpty() ? gp247_language_render('admin.core.no_records') : null">
            <x-slot:head>
                <tr>
                    <x-gp247::th-sort field="name" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.permission.name') }}</x-gp247::th-sort>
                    <x-gp247::th-sort field="slug" :sort-field="$sortField" :sort-dir="$sortDir">{{ gp247_language_render('admin.permission.slug') }}</x-gp247::th-sort>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.core.actions') }}</th>
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ (string) $row->id === (string) $editingId ? 'bg-blue-50 dark:bg-blue-900/30' : '' }}"
                    wire:key="perm-{{ $row->id }}">
                    <td class="px-4 py-3 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $row->name }}</td>
                    <td class="px-4 py-3 text-sm">
                        <code class="rounded bg-gray-100 px-1.5 py-0.5 text-xs dark:bg-gray-700">{{ $row->slug }}</code>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <x-gp247::button size="sm" variant="ghost" href="{{ gp247_route_admin('admin_permission.edit', $row->id) }}" wire:navigate>
                                <i class="fas fa-edit"></i>
                            </x-gp247::button>
                            <x-gp247::button size="sm" variant="ghost"
                                wire:click="delete('{{ $row->id }}')"
                                wire:confirm="{{ gp247_language_render('admin.permission.confirm_delete') }}">
                                <i class="fas fa-trash-alt text-red-600"></i>
                            </x-gp247::button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-gp247::table>

        <div class="mt-4">{{ $rows->links('gp247-admin::partials.pagination') }}</div>
    </x-gp247::card>

</div>
