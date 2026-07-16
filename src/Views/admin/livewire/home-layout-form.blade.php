{{--
    Home-page layout block create/edit form (ADR-001/005). UI text via
    gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-UI-003, US-UI-007
    @aidlc-adr ADR-001, ADR-005

    Variables: $views (string[] available block view ids).
--}}
<div class="max-w-2xl">
    <x-gp247::card :title="gp247_language_render($editingId ? 'action.edit' : 'admin.admin_home_layout.add_new_title')">
        <form wire:submit="save" class="space-y-4">

            {{-- View --}}
            <x-gp247::searchable-select
                model="form.view"
                :label="gp247_language_render('admin.admin_home_layout.view')"
                :options="collect($views)->map(fn ($v) => ['id' => $v, 'label' => $v])->all()"
                :error="$errors->first('form.view')"
                :required="true"
            />

            {{-- Size + Sort --}}
            <div class="grid grid-cols-2 gap-4">
                <x-gp247::input type="number" min="1" max="12"
                    :label="gp247_language_render('admin.admin_home_layout.size')" name="size" wire:model="form.size"
                    :help="'1 – 12'" :error="$errors->first('form.size')" required />

                <x-gp247::input type="number" min="0"
                    :label="gp247_language_render('admin.admin_home_layout.sort')" name="sort" wire:model="form.sort"
                    :error="$errors->first('form.sort')" required />
            </div>

            {{-- Status --}}
            <x-gp247::checkbox :label="gp247_language_render('admin.active')" wire:model="form.status" value="1" />

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_home_layout.index') }}" wire:navigate>
                    {{ gp247_language_render('admin.cancel') }}
                </x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled">
                    <i class="fas fa-save"></i> {{ gp247_language_render('admin.save') }}
                </x-gp247::button>
            </div>
        </form>
    </x-gp247::card>
</div>
