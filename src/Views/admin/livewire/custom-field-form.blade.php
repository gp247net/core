{{--
    Custom field create/edit form (ADR-001/005). UI text via gp247_language_render.

    @aidlc-unit admin-shell-rbac
    @aidlc-story US-RBAC-003, US-UI-007
    @aidlc-adr ADR-001, ADR-005

    Variables: $tables (entity type => label).

    WHY: `form.option` is the HTML input type the legacy `gp247_form_render_field()`
    helper (Library/Helpers/form.php) dispatches on to render this field on the
    front-end form (text/number/date/... fall through to a plain <input type=X>,
    while select/radio/checkbox/textarea get dedicated renderers). It must stay a
    fixed enum, not free text, so the two ends of the pipeline agree on values.
--}}
@php
    $optionTypes = [
        'text' => 'admin.custom_field.option_type_text',
        'textarea' => 'admin.custom_field.option_type_textarea',
        'number' => 'admin.custom_field.option_type_number',
        'date' => 'admin.custom_field.option_type_date',
        'month' => 'admin.custom_field.option_type_month',
        'week' => 'admin.custom_field.option_type_week',
        'time' => 'admin.custom_field.option_type_time',
        'email' => 'admin.custom_field.option_type_email',
        'password' => 'admin.custom_field.option_type_password',
        'url' => 'admin.custom_field.option_type_url',
        'color' => 'admin.custom_field.option_type_color',
        'select' => 'admin.custom_field.option_type_select',
        'radio' => 'admin.custom_field.option_type_radio',
        'checkbox' => 'admin.custom_field.option_type_checkbox',
    ];
@endphp
<div class="max-w-2xl">
    <x-gp247::card :title="gp247_language_render($editingId ? 'admin.custom_field.form_edit' : 'admin.custom_field.form_new')">
        <form wire:submit="save" class="space-y-4">
            <x-gp247::searchable-select
                model="form.type"
                :label="gp247_language_render('admin.custom_field.type')"
                :placeholder="gp247_language_render('admin.custom_field.select_entity')"
                :options="collect($tables)->map(fn ($label, $value) => ['id' => (string) $value, 'label' => $label])->values()->all()"
                :error="$errors->first('form.type')"
                :required="true"
            />

            <x-gp247::input :label="gp247_language_render('admin.custom_field.name')" name="name" wire:model="form.name"
                :error="$errors->first('form.name')" required />

            <x-gp247::input :label="gp247_language_render('admin.custom_field.code')" name="code" wire:model="form.code"
                :help="gp247_language_render('admin.custom_field.code_help')" :error="$errors->first('form.code')" required />

            <x-gp247::searchable-select
                model="form.option"
                :label="gp247_language_render('admin.custom_field.option')"
                :options="collect($optionTypes)->map(fn ($langKey, $value) => ['id' => $value, 'label' => gp247_language_render($langKey)])->values()->all()"
                :error="$errors->first('form.option')"
            />

            <x-gp247::input :label="gp247_language_render('admin.custom_field.default')" name="default" wire:model="form.default"
                :help="str_replace('<br>', ' ', gp247_language_render('admin.custom_field.default_help'))"
                :error="$errors->first('form.default')" />

            <div class="flex flex-col gap-3">
                <x-gp247::checkbox :label="gp247_language_render('admin.custom_field.required')" wire:model="form.required" value="1" />
                <x-gp247::checkbox :label="gp247_language_render('admin.active')" wire:model="form.status" value="1" />
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
                <x-gp247::button variant="secondary" href="{{ gp247_route_admin('admin_custom_field.index') }}" wire:navigate>{{ gp247_language_render('admin.cancel') }}</x-gp247::button>
                <x-gp247::button type="submit" wire:loading.attr="disabled"><i class="fas fa-save"></i> {{ gp247_language_render('admin.save') }}</x-gp247::button>
            </div>
        </form>
    </x-gp247::card>
</div>
