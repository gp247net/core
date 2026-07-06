<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\Models\AdminUser;

/**
 * Admin list of admin users (ADR-001/002/005). Gated by `admin_user`. The current
 * user and built-in admins (GP247_GUARD_ADMIN) cannot be deleted.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class UserList extends DataTableComponent
{
    protected ?string $permission = 'admin_user';

    protected ?string $titleKey = 'admin.user.title';

    protected string $guardedKey = 'admin.user.protected';

    /**
     * @return AdminUser
     */
    protected function query()
    {
        return new AdminUser();
    }

    /**
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return ['username' => 'Username', 'name' => 'Name', 'status' => 'Status'];
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
    protected function relations(): array
    {
        return ['roles'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    /**
     * @return string
     */
    protected function listView(): string
    {
        return 'gp247-admin::livewire.user-list';
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return ['protectedIds' => $this->protectedIds()];
    }

    /**
     * The base guard hook delegates to protectedIds().
     *
     * @return array<int, mixed>
     */
    protected function guardedIds(): array
    {
        return $this->protectedIds();
    }

    /**
     * @return array<int, string> Ids that must not be deleted: guarded admins + self.
     */
    public function protectedIds(): array
    {
        $guard = defined('GP247_GUARD_ADMIN') ? array_map('strval', (array) GP247_GUARD_ADMIN) : [];
        $self = admin()->user()?->id;

        return $self !== null ? array_merge($guard, [(string) $self]) : $guard;
    }
}
