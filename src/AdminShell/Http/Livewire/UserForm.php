<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\FormComponent;
use GP247\Core\Controllers\PasswordValidationTrait;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;

/**
 * Create/edit form for an admin user (ADR-001/005): profile, password (required on
 * create, optional on edit — hashed), status, and role assignment with the
 * brownfield "admin / view.all are exclusive" rule. Gated by `admin_user`.
 *
 * Reuses the core PasswordValidationTrait so the configured password policy
 * applies unchanged.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-005
 */
class UserForm extends FormComponent
{
    use PasswordValidationTrait;

    protected ?string $permission = 'admin_user';

    /** @var array<string, mixed> */
    public array $form = [
        'name' => '',
        'username' => '',
        'email' => '',
        'password' => '',
        'avatar' => '',
        'status' => 1,
        'roles' => [],
        'permissions' => [],
    ];

    /**
     * @param string|null $id User id to edit; null to create.
     * @return void
     */
    public function mount(?string $id = null): void
    {
        parent::mount();

        if ($id === null) {
            return;
        }

        // Editing your own account is done on the profile/setting screen.
        $self = admin()->user()?->id;
        if ($self !== null && (string) $self === (string) $id) {
            $this->redirectRoute('admin_user.index', navigate: true);

            return;
        }

        $user = AdminUser::with(['roles', 'permissions'])->findOrFail($id);
        $this->editingId = (string) $user->id;
        $this->form = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'password' => '',
            'avatar' => $user->avatar,
            'status' => (int) $user->status,
            'roles' => $user->roles->pluck('id')->map(fn ($i) => (string) $i)->all(),
            'permissions' => $user->permissions->pluck('id')->map(fn ($i) => (string) $i)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $table = (new AdminUser())->getTable();

        return [
            'form.name' => ['required', 'string', 'max:100'],
            'form.username' => ['required', 'string', 'min:3', 'max:100', 'regex:/^([0-9A-Za-z@._]+)$/', Rule::unique($table, 'username')->ignore($this->editingId, 'id')],
            'form.email' => ['required', 'string', 'email', 'max:255', Rule::unique($table, 'email')->ignore($this->editingId, 'id')],
            'form.avatar' => ['nullable', 'string', 'max:255'],
            'form.password' => $this->editingId === null ? $this->rulePassword() : $this->rulePasswordNullable(),
            'form.roles' => ['array'],
            'form.permissions' => ['array'],
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
     * Persist the user (hashing the raw password) and sync roles/permissions.
     *
     * @param array<string, mixed> $data Sanitised payload (password handled separately).
     * @return void
     */
    protected function persist(array $data): void
    {
        $attributes = [
            'name' => $data['name'],
            'username' => strtolower($data['username']),
            'email' => strtolower($data['email']),
            'avatar' => $data['avatar'] ?? '',
            'status' => empty($data['status']) ? 0 : 1,
        ];

        // WHY: hash the RAW password (not the gp247_clean'd value) so special
        // characters aren't HTML-escaped before hashing.
        $rawPassword = (string) ($this->form['password'] ?? '');
        if ($rawPassword !== '') {
            $attributes['password'] = bcrypt($rawPassword);
        }

        if ($this->editingId === null) {
            $user = AdminUser::createUser($attributes);
        } else {
            $user = AdminUser::findOrFail($this->editingId);
            $user->update($attributes);
        }

        // Built-in admins keep their role/permission set untouched.
        if (in_array((string) $user->id, $this->guardedIds(), true)) {
            return;
        }

        $roles = array_map('intval', $data['roles'] ?? []);
        $permissions = $data['permissions'] ?? [];

        // Brownfield rule: the administrator (1) and view.all (2) roles are
        // exclusive and grant everything, so direct permissions are cleared.
        if (in_array(1, $roles, true)) {
            $roles = [1];
            $permissions = [];
        } elseif (in_array(2, $roles, true)) {
            $roles = [2];
            $permissions = [];
        }

        $user->roles()->sync($roles);
        $user->permissions()->sync($permissions);
    }

    /**
     * Save, then return to the list with a flash.
     *
     * @return void
     */
    public function save(): void
    {
        parent::save();

        session()->flash('gp247_admin_success', gp247_language_render('admin.core.save_success'));
        $this->redirectRoute('admin_user.index', navigate: true);
    }

    /**
     * @return array<int, string> Guarded admin ids.
     */
    private function guardedIds(): array
    {
        return defined('GP247_GUARD_ADMIN') ? array_map('strval', (array) GP247_GUARD_ADMIN) : [];
    }

    /**
     * @return array{name: string, url: string}
     */
    protected function listCrumb(): array
    {
        return ['name' => gp247_language_render('admin.user.title'), 'url' => route('admin_user.index')];
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('gp247-admin::livewire.user-form', [
            'allRoles' => AdminRole::orderBy('name')->get(['id', 'name']),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => $this->editingId !== null ? 'Edit User' : 'New User',
            'breadcrumb' => $this->listCrumb(),
        ]);
    }
}
