{{--
    Role create/edit form (ADR-001/005): name, slug, permission assignment and
    user (administrator) assignment. Checkbox lists bind to form arrays and are
    synced to the pivot tables on save. UI text via gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-001, ADR-005

    Variables: $allPermissions (id,name,slug), $allUsers (id,name).
--}}
<div class="max-w-3xl">
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.role.form_edit' : 'admin.role.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.role.name')" name="name" wire:model="form.name"
                :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.role.slug')" name="slug" wire:model="form.slug"
                :help="gp247_language_render('admin.role.slug_help')" :error="$errors->first('form.slug')" required />

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ gp247_language_render('admin.role.permissions') }} ({{ gp247_language_render('admin.core.selected', ['count' => count($form['permissions'])]) }})
                </label>
                <div x-data="{ q: '' }" class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="border-b border-gray-200 p-2 dark:border-gray-700">
                        <input type="text" x-model="q" placeholder="{{ gp247_language_render('admin.role.filter_permissions') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </div>
                    <div class="grid max-h-72 grid-cols-1 gap-1 overflow-y-auto p-3 sm:grid-cols-2">
                        @foreach ($allPermissions as $p)
                            <label x-show="'{{ strtolower($p->name . ' ' . $p->slug) }}'.includes(q.toLowerCase())"
                                class="flex cursor-pointer select-none items-center gap-2 rounded px-2 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <x-gp247::checkbox wire:model="form.permissions" value="{{ $p->id }}" />
                                <span class="text-gray-700 dark:text-gray-200">{{ $p->name }}</span>
                                <code class="text-xs text-gray-400">{{ $p->slug }}</code>
                            </label>
                        @endforeach
                        @if ($allPermissions->isEmpty())<p class="text-sm text-gray-400">{{ gp247_language_render('admin.role.no_permissions') }}</p>@endif
                    </div>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ gp247_language_render('admin.role.users_in_role') }} ({{ gp247_language_render('admin.core.selected', ['count' => count($form['administrators'])]) }})
                </label>
                <div x-data="{ q: '' }" class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="border-b border-gray-200 p-2 dark:border-gray-700">
                        <input type="text" x-model="q" placeholder="{{ gp247_language_render('admin.role.filter_users') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </div>
                    <div class="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto p-3 sm:grid-cols-2">
                        @foreach ($allUsers as $u)
                            <label x-show="'{{ strtolower((string) $u->name) }}'.includes(q.toLowerCase())"
                                class="flex cursor-pointer select-none items-center gap-2 rounded px-2 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <x-gp247::checkbox wire:model="form.administrators" value="{{ $u->id }}" />
                                <span class="text-gray-700 dark:text-gray-200">{{ $u->name }}</span>
                            </label>
                        @endforeach
                        @if ($allUsers->isEmpty())<p class="text-sm text-gray-400">{{ gp247_language_render('admin.role.no_users') }}</p>@endif
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_role.index') }}" wire:navigate>{{ gp247_language_render('admin.core.cancel') }}</x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled"><i class="fas fa-save"></i> {{ gp247_language_render('admin.core.save') }}</x-gp247::button>
            </div>
        </form>
    </x-gp247::card>
</div>
