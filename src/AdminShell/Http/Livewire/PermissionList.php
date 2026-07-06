<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\Models\AdminPermission;

/**
 * Admin list of RBAC permissions (ADR-001/002/005). Gated by `admin_permission`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class PermissionList extends DataTableComponent
{
    protected ?string $permission = 'admin_permission';

    protected ?string $titleKey = 'admin.permission.title';

    /**
     * @return AdminPermission
     */
    protected function query()
    {
        return new AdminPermission();
    }

    /**
     * @return array<string, string> Sortable columns.
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
     * @return string
     */
    protected function listView(): string
    {
        return 'gp247-admin::livewire.permission-list';
    }
}
