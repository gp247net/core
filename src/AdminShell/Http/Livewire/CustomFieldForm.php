<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\FormComponent;
use GP247\Core\Models\AdminCustomField;
use Illuminate\Contracts\View\View;

/**
 * Create/edit form for a custom field (ADR-001/005): the entity type it attaches
 * to, a slugified code, label, options/default, and required/status flags. Gated
 * by `admin_custom_field`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005
 */
class CustomFieldForm extends FormComponent
{
    protected ?string $permission = 'admin_custom_field';

    /** @var array<string, mixed> */
    public array $form = [
        'type' => '',
        'code' => '',
        'name' => '',
        'option' => '',
        'default' => '',
        'required' => 0,
        'status' => 1,
    ];

    /**
     * @param string|null $id Custom field id to edit; null to create.
     * @return void
     */
    public function mount(?string $id = null): void
    {
        parent::mount();

        if ($id !== null) {
            $field = AdminCustomField::findOrFail($id);
            $this->editingId = (string) $field->id;
            $this->form = [
                'type' => $field->type,
                'code' => $field->code,
                'name' => $field->name,
                'option' => (string) $field->option,
                'default' => (string) $field->default,
                'required' => (int) $field->required,
                'status' => (int) $field->status,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'form.type' => ['required', 'string', 'max:100'],
            'form.code' => ['required', 'string', 'max:100', $this->uniqueCodePerTypeRule()],
            'form.name' => ['required', 'string', 'max:250'],
            'form.option' => ['nullable', 'string', 'in:text,textarea,number,date,month,week,time,email,password,url,color,select,radio,checkbox'],
            'form.default' => ['nullable', 'string'],
        ];
    }

    /**
     * Closure rule enforcing a unique (type, code) pair at the application layer.
     *
     * The code is stored after normalization (gp247_word_format_url), so uniqueness must
     * be checked against the same normalized token — not the raw input — and scoped to the
     * selected type, ignoring the record being edited. This blocks the silent field-collapse
     * caused by keyBy('code') / first() downstream when two definitions share a code.
     * A DB-level unique index is intentionally deferred (existing installs may already hold
     * duplicates; adding the index unguarded would break their update). See ADR/backlog.
     *
     * @return \Closure Validation closure: fn(string $attribute, mixed $value, \Closure $fail): void
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-custom-field-hardening
     * @aidlc-adr ADR-compat-foundation-custom-field-integrity
     */
    protected function uniqueCodePerTypeRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $type = (string) ($this->form['type'] ?? '');
            if ($type === '') {
                return; // type rule reports its own error; nothing to scope against yet.
            }

            $normalizedCode = gp247_word_limit(gp247_word_format_url((string) $value), 100);

            $exists = AdminCustomField::where('type', $type)
                ->where('code', $normalizedCode)
                ->when($this->editingId !== null, fn ($query) => $query->where('id', '!=', $this->editingId))
                ->exists();

            if ($exists) {
                $fail(gp247_language_render('admin.custom_field.code_unique_per_type', ['code' => $normalizedCode]));
            }
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    protected function persist(array $data): void
    {
        $attributes = [
            'type' => $data['type'],
            'name' => $data['name'],
            // Normalise the code to a url-safe token, mirroring the brownfield flow.
            'code' => gp247_word_limit(gp247_word_format_url($data['code']), 100),
            'option' => $data['option'] ?? '',
            // WHY: keep the default value raw (not HTML-escaped), as the legacy editor does.
            'default' => (string) ($this->form['default'] ?? ''),
            'required' => empty($data['required']) ? 0 : 1,
            'status' => empty($data['status']) ? 0 : 1,
        ];

        if ($this->editingId !== null) {
            AdminCustomField::where('id', $this->editingId)->update($attributes);

            return;
        }

        AdminCustomField::create($attributes);
    }

    /**
     * Save, then return to the list with a flash.
     *
     * @return void
     */
    public function save(): void
    {
        parent::save();

        session()->flash('gp247_admin_success', gp247_language_render('admin.save_success'));
        $this->redirectRoute('admin_custom_field.index', navigate: true);
    }

    /**
     * @return array{name: string, url: string}
     */
    protected function listCrumb(): array
    {
        return ['name' => gp247_language_render('admin.custom_field.title'), 'url' => route('admin_custom_field.index')];
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.custom-field-form', [
            'tables' => function_exists('gp247_custom_field_get_tables') ? gp247_custom_field_get_tables() : [],
        ])->layout('gp247-admin::layouts.admin', [
            'title' => $this->editingId !== null ? 'Edit Custom field' : 'New Custom field',
            'breadcrumb' => $this->listCrumb(),
        ]);
    }
}
