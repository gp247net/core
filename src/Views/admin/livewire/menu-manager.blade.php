{{--
    Two-panel menu manager (ADR-005): create/edit form (left) + hierarchical menu
    tree (right) on one page, matching the legacy two-column layout. Edit loads a
    node into the form via the edit/{id} route; the tree supports drag-and-drop
    reordering (menu-node partial) and per-node delete. UI text via
    gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-001, ADR-002, ADR-005

    Variables: $parentOptions (id => label), $grouped (menus grouped by parent_id).
--}}
<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- Left: create / edit form --}}
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.menu.form_edit' : 'admin.menu.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.menu.field_title')" name="title" wire:model="form.title"
                :help="gp247_language_render('admin.menu.title_help')"
                :error="$errors->first('form.title')" required />

            <x-gp247::searchable-select
                model="form.parent_id"
                :label="gp247_language_render('admin.menu.parent')"
                :options="collect($parentOptions)->map(fn ($label, $value) => ['id' => (string) $value, 'label' => $label])->values()->all()"
            />

            <x-gp247::input :label="gp247_language_render('admin.menu.uri')" name="uri" wire:model="form.uri"
                :help="gp247_language_render('admin.menu.uri_help')"
                :error="$errors->first('form.uri')" />

            <x-gp247::input :label="gp247_language_render('admin.menu.icon_field')" name="icon" wire:model="form.icon"
                :help="gp247_language_render('admin.menu.icon_help')" :error="$errors->first('form.icon')" />

            <x-gp247::input :label="gp247_language_render('admin.sort')" name="sort" type="number" wire:model="form.sort"
                :error="$errors->first('form.sort')" />

            <div class="flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_menu.index') }}" wire:navigate>{{ gp247_language_render($editingId ? 'admin.cancel' : 'admin.reset') }}</x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled"><i class="fas fa-save"></i> {{ gp247_language_render($editingId ? 'admin.update' : 'admin.submit') }}</x-gp247::button>
            </div>
        </form>
    </x-gp247::card>

    {{-- Right: hierarchical menu tree --}}
    <x-gp247::card :title="gp247_language_render('admin.menu.list_title')">
        <p class="mb-2 text-xs text-gray-400 dark:text-gray-500">
            <i class="fas fa-grip-vertical"></i> {{ gp247_language_render('admin.menu.drag_hint') }}
        </p>

        <ul class="divide-y divide-gray-100 dark:divide-gray-700" data-gp247-sortable data-parent="0">
            @forelse ($grouped[0] ?? [] as $node)
                @include('gp247-admin::partials.menu-node', ['node' => $node, 'grouped' => $grouped, 'depth' => 0])
            @empty
                <li class="py-2 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.menu.no_menus') }}</li>
            @endforelse
        </ul>
    </x-gp247::card>
</div>
