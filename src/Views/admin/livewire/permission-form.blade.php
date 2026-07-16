{{--
    Permission create/edit form (ADR-001/005): name, slug, and an admin-route URI
    picker (grouped checkboxes with a client-side filter). Selected URIs bind to
    form.http_uri (array) and are stored comma-joined on save. UI text via
    gp247_language_render (seeded in gp247_languages).

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-001, ADR-005

    Variables: $routeGroups (array<string, array<int, array{uri,method,path}>>).
--}}
<div class="max-w-3xl">
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.permission.form_edit' : 'admin.permission.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.permission.name')" name="name" wire:model="form.name"
                :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.permission.slug')" name="slug" wire:model="form.slug"
                :help="gp247_language_render('admin.permission.slug_help')"
                :error="$errors->first('form.slug')" required />

            <div>
                <div class="mb-1 flex items-center justify-between">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ gp247_language_render('admin.permission.allowed_routes') }} ({{ gp247_language_render('admin.selected', ['count' => count($form['http_uri'])]) }})
                    </label>
                </div>

                <div x-data="{ q: '' }" class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="border-b border-gray-200 p-2 dark:border-gray-700">
                        <input type="text" x-model="q" placeholder="{{ gp247_language_render('admin.permission.filter_routes') }}"
                            class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </div>

                    <div class="max-h-96 space-y-3 overflow-y-auto p-3">
                        @foreach ($routeGroups as $group => $routes)
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $group }}</p>
                                <div class="space-y-1">
                                    @foreach ($routes as $r)
                                        <label data-route x-show="'{{ strtolower($r['path']) }}'.includes(q.toLowerCase()) || '{{ strtolower($group) }}'.includes(q.toLowerCase())"
                                            class="flex cursor-pointer select-none items-center gap-2 rounded px-2 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                            <x-gp247::checkbox wire:model="form.http_uri" value="{{ $r['uri'] }}" />
                                            <x-gp247::badge :color="$r['method'] === 'ANY' ? 'blue' : ($r['method'] === 'POST' ? 'amber' : 'gray')">{{ $r['method'] }}</x-gp247::badge>
                                            <code class="text-xs text-gray-600 dark:text-gray-300">{{ $r['path'] }}</code>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @error('form.http_uri')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_permission.index') }}" wire:navigate>{{ gp247_language_render('admin.cancel') }}</x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled"><i class="fas fa-save"></i> {{ gp247_language_render('admin.save') }}</x-gp247::button>
            </div>
        </form>
    </x-gp247::card>
</div>
