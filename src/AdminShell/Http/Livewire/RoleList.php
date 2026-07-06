<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\Models\AdminRole;

/**
 * Admin list of RBAC roles (ADR-001/002/005). Gated by `admin_role`. Built-in
 * roles (GP247_GUARD_ROLES) cannot be edited or deleted.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class RoleList extends DataTableComponent
{
    protected ?string $permission = 'admin_role';

    protected ?string $titleKey = 'admin.role.title';

    protected string $guardedKey = 'admin.role.protected';

    /**
     * @return AdminRole
     */
    protected function query()
    {
        return new AdminRole();
    }

    /**
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return ['name' => 'Name', 'slug' => 'Slug'];
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
    protected function relations(): array
    {
        return ['permissions'];
    }

    /**
     * @return string
     */
    protected function listView(): string
    {
        return 'gp247-admin::livewire.role-list';
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return ['guardedIds' => $this->guardedIds()];
    }

    /**
     * @return array<int, int> Built-in role ids that must not be edited/deleted.
     */
    public function guardedIds(): array
    {
        return defined('GP247_GUARD_ROLES') ? array_map('intval', (array) GP247_GUARD_ROLES) : [];
    }
}
