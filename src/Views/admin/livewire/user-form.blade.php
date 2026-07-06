{{--
    User create/edit form (ADR-001/005): profile, password (blank = keep on edit),
    avatar (media-input), status, and role assignment. Saves with hashing + pivot
    sync in the component. UI text via gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-001, ADR-005

    Variables: $allRoles (id, name).
--}}
<div class="max-w-2xl">
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.user.form_edit' : 'admin.user.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::input :label="gp247_language_render('admin.user.name')" name="name" wire:model="form.name"
                :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.user.username')" name="username" wire:model="form.username"
                :help="gp247_language_render('admin.user.username_help')" :error="$errors->first('form.username')" required />

            <x-gp247::input :label="gp247_language_render('admin.user.email')" name="email" type="email" wire:model="form.email"
                :error="$errors->first('form.email')" required />

            <x-gp247::input :label="gp247_language_render('admin.user.password')" name="password" type="password" wire:model="form.password"
                :help="$editingId ? gp247_language_render('admin.user.password_keep') : null"
                :error="$errors->first('form.password')" :required="! $editingId" />

            <x-gp247::media-input :label="gp247_language_render('admin.user.avatar')" name="avatar" type="avatar"
                wire:model="form.avatar" :value="$form['avatar'] ?? null"
                :error="$errors->first('form.avatar')" />

            <x-gp247::checkbox :label="gp247_language_render('admin.core.active')" wire:model="form.status" value="1" />

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ gp247_language_render('admin.user.roles') }} ({{ gp247_language_render('admin.core.selected', ['count' => count($form['roles'])]) }})
                </label>
                <div class="grid grid-cols-1 gap-1 rounded-lg border border-gray-200 p-3 dark:border-gray-700 sm:grid-cols-2">
                    @foreach ($allRoles as $r)
                        <label class="flex cursor-pointer select-none items-center gap-2 rounded px-2 py-1 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <x-gp247::checkbox wire:model="form.roles" value="{{ $r->id }}" />
                            <span class="text-gray-700 dark:text-gray-200">{{ $r->name }}</span>
                        </label>
                    @endforeach
                    @if ($allRoles->isEmpty())<p class="text-sm text-gray-400">{{ gp247_language_render('admin.user.no_roles') }}</p>@endif
                </div>
                <p class="mt-1 text-xs text-gray-400">{{ gp247_language_render('admin.user.role_override_note') }}</p>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_user.index') }}" wire:navigate>{{ gp247_language_render('admin.core.cancel') }}</x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled"><i class="fas fa-save"></i> {{ gp247_language_render('admin.core.save') }}</x-gp247::button>
            </div>
        </form>
    </x-gp247::card>
</div>
