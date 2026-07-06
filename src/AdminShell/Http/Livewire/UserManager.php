<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\ResourcePanel;
use GP247\Core\Controllers\PasswordValidationTrait;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;

/**
 * Two-panel user manager (ADR-005): add/edit form left + live list right.
 * Extends ResourcePanel; avatar (LFM), status and role assignment are included
 * inline. The current user and GP247_GUARD_ADMIN are protected.
 * Gated by `admin_user`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005, ADR-007
 */
class UserManager extends ResourcePanel
{
    use PasswordValidationTrait;

    protected ?string $permission = 'admin_user';

    /**
     * Raw password (before gp247_clean) saved in save() and used in persist().
     *
     * @var string
     */
    private string $rawPassword = '';

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function baseQuery()
    {
        return AdminUser::with(['roles']);
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['name', 'username'];
    }

    /**
     * @return array<int, string>
     */
    protected function sortableColumns(): array
    {
        return ['username', 'name', 'status'];
    }

    /**
     * @return array<int, string>
     */
    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    /**
     * @return string
     */
    protected function panelView(): string
    {
        return 'gp247-admin::livewire.user-manager';
    }

    /**
     * @return string
     */
    protected function pageTitle(): string
    {
        return gp247_language_render('admin.user.title');
    }

    /**
     * @return string
     */
    protected function baseRoute(): string
    {
        return 'admin_user.index';
    }

    /**
     * @return array<string, mixed>
     */
    protected function formDefaults(): array
    {
        return ['name' => '', 'username' => '', 'email' => '', 'password' => '', 'avatar' => '', 'status' => 1, 'roles' => []];
    }

    /**
     * @param AdminUser $model
     * @return array<string, mixed>
     */
    protected function fillForm($model): array
    {
        $user = AdminUser::with(['roles'])->find($model->id);

        return [
            'name'     => (string) $user->name,
            'username' => (string) $user->username,
            'email'    => (string) $user->email,
            'password' => '',
            'avatar'   => (string) ($user->avatar ?? ''),
            'status'   => (int) $user->status,
            'roles'    => $user->roles->pluck('id')->map(fn ($i) => (string) $i)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $table = (new AdminUser())->getTable();

        return [
            'form.name'     => ['required', 'string', 'max:100'],
            'form.username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^([0-9A-Za-z@._]+)$/', Rule::unique($table, 'username')->ignore($this->editingId, 'id')],
            'form.email'    => ['required', 'string', 'email', 'max:255', Rule::unique($table, 'email')->ignore($this->editingId, 'id')],
            'form.avatar'   => ['nullable', 'string', 'max:255'],
            'form.password' => $this->editingId === null ? $this->rulePassword() : $this->rulePasswordNullable(),
            'form.roles'    => ['array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['form.username.regex' => 'The username may only contain letters, numbers, @, dot and underscore.'];
    }

    /**
     * Skip editing the currently logged-in user (use profile/setting instead)
     * and skip protected users.
     *
     * @param int|string $id
     * @return void
     */
    public function editRow($id): void
    {
        if (in_array((string) $id, $this->protectedIds(), true)) {
            return;
        }

        parent::editRow($id);
    }

    /**
     * Capture raw password before gp247_clean strips special characters.
     *
     * @return void
     */
    public function save(): void
    {
        $this->rawPassword = (string) ($this->form['password'] ?? '');
        $this->form['password'] = '';
        parent::save();
    }

    /**
     * @param array<string, mixed> $data
     * @return void
     */
    protected function persist(array $data): void
    {
        $attributes = [
            'name'     => $data['name'],
            'username' => strtolower($data['username']),
            'email'    => strtolower($data['email']),
            'avatar'   => $data['avatar'] ?? '',
            'status'   => empty($data['status']) ? 0 : 1,
        ];

        if ($this->rawPassword !== '') {
            $attributes['password'] = bcrypt($this->rawPassword);
        }

        if ($this->editingId === null) {
            $user = AdminUser::createUser($attributes);
        } else {
            $user = AdminUser::findOrFail($this->editingId);
            $user->update($attributes);
        }

        if (in_array((string) $user->id, array_map('strval', defined('GP247_GUARD_ADMIN') ? (array) GP247_GUARD_ADMIN : []), true)) {
            return;
        }

        $roles = array_map('intval', $data['roles'] ?? []);

        // Brownfield rule: administrator (1) or view.all (2) roles grant everything.
        if (in_array(1, $roles, true)) {
            $roles = [1];
        } elseif (in_array(2, $roles, true)) {
            $roles = [2];
        }

        $user->roles()->sync($roles);
    }

    /**
     * @param int|string $id
     * @return void
     */
    protected function deleteModel($id): void
    {
        if (in_array((string) $id, $this->protectedIds(), true)) {
            return;
        }

        $model = AdminUser::find($id);
        if ($model !== null) {
            $model->delete();
        }
    }

    /**
     * @return array<int, string> Ids that must not be edited/deleted: guarded admins + self.
     */
    public function protectedIds(): array
    {
        $guard = defined('GP247_GUARD_ADMIN') ? array_map('strval', (array) GP247_GUARD_ADMIN) : [];
        $self  = admin()->user()?->id;

        return $self !== null ? array_merge($guard, [(string) $self]) : $guard;
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view($this->panelView(), [
            'rows'         => $this->rows(),
            'protectedIds' => $this->protectedIds(),
            'roleOptions'  => AdminRole::orderBy('name')->get(['id', 'name'])
                ->map(fn ($r) => ['id' => (string) $r->id, 'label' => (string) $r->name])
                ->all(),
        ])->layout('gp247-admin::layouts.admin', ['title' => $this->pageTitle()]);
    }
}
