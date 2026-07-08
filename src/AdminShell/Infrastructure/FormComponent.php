<?php

namespace GP247\Core\AdminShell\Infrastructure;

/**
 * Abstract create/edit form base for admin Livewire screens (ADR-005).
 *
 * Centralises the secure save pipeline: Layer-2 authorization (store vs update),
 * validation (concrete rules() preserve brownfield constraints), gp247_clean
 * sanitization, then persist(). Real-time validation runs on each field update.
 * Concrete forms implement rules() and persist(); the view is component-specific.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-003
 * @aidlc-adr ADR-001, ADR-005
 */
abstract class FormComponent extends GP247AdminComponent
{
    /** @var array<string, mixed> Editable form payload (bound via wire:model). */
    public array $form = [];

    /** @var string|null Id of the record being edited; null when creating. */
    public ?string $editingId = null;

    /**
     * form.* field names holding admin-authored rich HTML (TinyMCE) that must
     * survive save() as-is. gp247_clean() htmlspecialchars-escapes its input,
     * which corrupts real markup (e.g. a Layout Block's `text` when type=html);
     * concrete forms with a rich-editor field must list it here. Mirrors the
     * RICH_FIELDS pattern in WebsiteInfo — safe because these screens are
     * already RBAC/Layer-2 authorized.
     *
     * @var array<int, string>
     */
    protected array $richFields = [];

    /**
     * Validation rules keyed by form field path (e.g. "form.name").
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * Persist the sanitised payload (insert when creating, update otherwise).
     *
     * @param array<string, mixed> $data Sanitised form values.
     * @return void
     */
    abstract protected function persist(array $data): void;

    /**
     * Livewire hook: validate a single field as the user edits it.
     *
     * @param string $field The updated property path.
     * @return void
     */
    public function updated(string $field): void
    {
        $this->validateOnly($field);
    }

    /**
     * Mid-level breadcrumb crumb pointing to the list screen. Override in
     * concrete forms and return ['name' => <label>, 'url' => <route>] so the
     * breadcrumb partial renders "Home › List › Edit/New". Return null for
     * screens that have no parent list (e.g. standalone settings forms).
     *
     * @return array{name: string, url: string|null}|null
     */
    protected function listCrumb(): ?array
    {
        return null;
    }

    /**
     * Authorize, validate, sanitize and persist the form.
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     * @throws \Illuminate\Validation\ValidationException When validation fails.
     */
    public function save(): void
    {
        $this->authorizeAction($this->editingId !== null ? 'update' : 'store');

        $this->validate();

        // WHY: escape HTML at the boundary so persisted data can't carry markup
        // (XSS defense, NFR-SEC-004) — mirrors the brownfield controller flow.
        // richFields are excluded so admin-authored rich HTML isn't escaped.
        $clean = gp247_clean($this->form, $this->richFields);

        $this->persist($clean);

        $this->notify('success', gp247_language_render('admin.core.save_success'));
        $this->dispatch('saved');
    }
}
