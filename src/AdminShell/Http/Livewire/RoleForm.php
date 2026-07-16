<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\FormComponent;
use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;

/**
 * Create/edit form for an RBAC role (ADR-001/005): name + slug, plus permission
 * and user (administrator) assignment synced to the pivot tables. Gated by
 * `admin_role`. Built-in roles (GP247_GUARD_ROLES) are not editable here.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005
 */
class RoleForm extends FormComponent
{
    protected ?string $permission = 'admin_role';

    /** @var array<string, mixed> name, slug, permissions[] (ids), administrators[] (user ids). */
    public array $form = ['name' => '', 'slug' => '', 'permissions' => [], 'administrators' => []];

    /**
     * @param string|null $id Role id to edit; null to create.
     * @return void
     */
    public function mount(?string $id = null): void
    {
        parent::mount();

        if ($id !== null) {
            $role = AdminRole::with(['permissions', 'administrators'])->findOrFail($id);
            $this->editingId = (string) $role->id;
            $this->form = [
                'name' => $role->name,
                'slug' => $role->slug,
                'permissions' => $role->permissions->pluck('id')->map(fn ($i) => (string) $i)->all(),
                'administrators' => $role->administrators->pluck('id')->map(fn ($i) => (string) $i)->all(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $table = (new AdminRole())->getTable();

        return [
            'form.name' => ['required', 'string', 'max:50', Rule::unique($table, 'name')->ignore($this->editingId)],
            'form.slug' => ['required', 'string', 'min:3', 'max:50', 'regex:/^([0-9A-Za-z._\-]+)$/', Rule::unique($table, 'slug')->ignore($this->editingId)],
            'form.permissions' => ['array'],
            'form.administrators' => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['form.slug.regex' => 'The slug may only contain letters, numbers, dot, dash and underscore.'];
    }

    /**
     * Persist the role and sync its permission/user pivots.
     *
     * @param array<string, mixed> $data Sanitised payload.
     * @return void
     */
    protected function persist(array $data): void
    {
        $attributes = ['name' => $data['name'], 'slug' => $data['slug']];

        if ($this->editingId !== null) {
            $role = AdminRole::findOrFail($this->editingId);
            $role->update($attributes);
        } else {
            $role = AdminRole::create($attributes);
        }

        $role->permissions()->sync($data['permissions'] ?? []);
        $role->administrators()->sync($data['administrators'] ?? []);
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
        $this->redirectRoute('admin_role.index', navigate: true);
    }

    /**
     * @return array{name: string, url: string}
     */
    protected function listCrumb(): array
    {
        return ['name' => gp247_language_render('admin.role.title'), 'url' => route('admin_role.index')];
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.role-form', [
            'allPermissions' => AdminPermission::orderBy('name')->get(['id', 'name', 'slug']),
            'allUsers' => AdminUser::orderBy('name')->get(['id', 'name']),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => $this->editingId !== null ? 'Edit Role' : 'New Role',
            'breadcrumb' => $this->listCrumb(),
        ]);
    }
}
