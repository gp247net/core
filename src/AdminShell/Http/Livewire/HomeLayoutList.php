<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\Models\AdminHome;

/**
 * Admin home-page layout blocks list (ADR-001/002/005): the dashboard widgets
 * with their grid size, sort order and on/off status, plus a "view exists" health
 * check. Row Edit/Delete and bulk delete. Mirrors the legacy
 * AdminHomeLayoutController list. Gated by `admin_home_layout`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class HomeLayoutList extends DataTableComponent
{
    protected ?string $permission = 'admin_home_layout';

    protected ?string $titleKey = 'admin.admin_home_layout.list';

    /**
     * @return AdminHome
     */
    protected function query()
    {
        return new AdminHome();
    }

    /**
     * Sortable columns; doubles as the sort whitelist.
     *
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return [
            'view' => 'View',
            'size' => 'Size',
            'sort' => 'Sort',
            'status' => 'Status',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['view'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function defaultSort(): array
    {
        return ['sort', 'desc'];
    }

    /**
     * @return string
     */
    protected function listView(): string
    {
        return 'gp247-admin::livewire.home-layout-list';
    }
}
