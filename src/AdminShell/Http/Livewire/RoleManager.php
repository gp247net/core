<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ResourcePanel;
use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;

/**
 * Two-panel role manager (ADR-005): add/edit form left + live list right.
 * Extends ResourcePanel; permission and user assignment pickers are included
 * inline. Built-in roles (GP247_GUARD_ROLES) are read-only. Gated by `admin_role`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005, ADR-007
 */
class RoleManager extends ResourcePanel
{
    protected ?string $permission = 'admin_role';

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function baseQuery()
    {
        return AdminRole::with(['permissions']);
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['name', 'slug'];
    }

    /**
     * @return array<int, string>
     */
    protected function sortableColumns(): array
    {
        return ['name', 'slug'];
    }

    /**
     * @return string
     */
    protected function panelView(): string
    {
        return 'gp247-admin::livewire.role-manager';
    }

    /**
     * @return string
     */
    protected function pageTitle(): string
    {
        return gp247_language_render('admin.role.title');
    }

    /**
     * @return string
     */
    protected function baseRoute(): string
    {
        return 'admin_role.index';
    }

    /**
     * @return array<string, mixed>
     */
    protected function formDefaults(): array
    {
        return ['name' => '', 'slug' => '', 'permissions' => [], 'administrators' => []];
    }

    /**
     * @param AdminRole $model
     * @return array<string, mixed>
     */
    protected function fillForm($model): array
    {
        $role = AdminRole::with(['permissions', 'administrators'])->find($model->id);

        return [
            'name'           => (string) $role->name,
            'slug'           => (string) $role->slug,
            'permissions'    => $role->permissions->pluck('id')->map(fn ($i) => (string) $i)->all(),
            'administrators' => $role->administrators->pluck('id')->map(fn ($i) => (string) $i)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $table = (new AdminRole())->getTable();

        return [
            'form.name'           => ['required', 'string', 'max:50', Rule::unique($table, 'name')->ignore($this->editingId)],
            'form.slug'           => ['required', 'string', 'min:3', 'max:50', 'regex:/^([0-9A-Za-z._\-]+)$/', Rule::unique($table, 'slug')->ignore($this->editingId)],
            'form.permissions'    => ['array'],
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
     * @param array<string, mixed> $data
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
     * @param int|string $id
     * @return void
     */
    protected function deleteModel($id): void
    {
        if (in_array((int) $id, $this->guardedIds(), true)) {
            return;
        }

        $model = AdminRole::find($id);
        if ($model !== null) {
            $model->delete();
        }
    }

    /**
     * Skip editing guarded (built-in) roles.
     *
     * @param int|string $id
     * @return void
     */
    public function editRow($id): void
    {
        if (in_array((int) $id, $this->guardedIds(), true)) {
            return;
        }

        parent::editRow($id);
    }

    /**
     * @return array<int, int> Built-in role ids that must not be edited/deleted.
     */
    public function guardedIds(): array
    {
        return defined('GP247_GUARD_ROLES') ? array_map('intval', (array) GP247_GUARD_ROLES) : [];
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view($this->panelView(), [
            'rows'        => $this->rows(),
            'guardedIds'  => $this->guardedIds(),
            'permOptions' => AdminPermission::orderBy('name')->get(['id', 'name', 'slug'])
                ->map(fn ($p) => ['id' => (string) $p->id, 'label' => $p->name . ' (' . $p->slug . ')'])
                ->all(),
            'userOptions' => AdminUser::orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => (string) $u->id, 'label' => (string) $u->name])
                ->all(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->pageTitle()]);
    }
}
