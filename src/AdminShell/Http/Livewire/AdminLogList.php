<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\Models\AdminLog;

/**
 * Admin operation-log list (ADR-001/002/005): read-only rows with per-row and
 * bulk delete, keyword search over IP/Path, and method/time sorting. Mirrors the
 * legacy AdminLogController list screen. Gated by `admin_log`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-UI-002
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class AdminLogList extends DataTableComponent
{
    protected ?string $permission = 'admin_log';

    protected ?string $titleKey = 'admin.log.list';

    /**
     * @return AdminLog
     */
    protected function query()
    {
        return new AdminLog();
    }

    /**
     * Sortable columns; doubles as the sort whitelist.
     *
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return [
            'user_id' => 'UID',
            'method' => 'Method',
            'path' => 'Path',
            'ip' => 'IP',
            'created_at' => 'Created at',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['ip', 'path'];
    }

    /**
     * Eager-load the acting admin user so the table avoids N+1 lookups.
     *
     * @return array<int, string>
     */
    protected function relations(): array
    {
        return ['user'];
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
        return 'gp247-admin::livewire.admin-log-list';
    }

    /**
     * Expose the HTTP-method → badge-color map to the view.
     *
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return ['methodColors' => AdminLog::$methodColors];
    }
}
