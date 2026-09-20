<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\AdminShell\Support\LivewireOperationLog;
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

    /**
     * What a row actually did, for the "detail" column: the action name, the
     * fields it touched, its parameters, and the decoded input for the
     * expandable view. A path alone says where the admin was, not what changed.
     *
     * - LIVEWIRE rows (LivewireOperationLog): action = the component method,
     *   fields = the properties changed in that request, params = its arguments.
     * - Classic POST/PUT/DELETE rows: fields = the top-level input keys.
     * - GET rows carry no input and show nothing.
     *
     * Input is stored HTML-escaped by gp247_clean(), hence the decode first.
     *
     * @param AdminLog $row
     * @return array{action: string|null, fields: array<int, string>, params: array<int, string>, full: string|null}
     */
    public function detail(AdminLog $row): array
    {
        $raw = html_entity_decode((string) $row->input, ENT_QUOTES);
        $input = json_decode($raw, true);
        $empty = ['action' => null, 'fields' => [], 'params' => [], 'full' => null];
        if (!is_array($input) || $input === []) {
            return $empty;
        }

        if ($row->method === LivewireOperationLog::METHOD) {
            $action = trim((string) ($input['method'] ?? '')) ?: null;
            $fields = array_map('strval', array_keys(is_array($input['changed'] ?? null) ? $input['changed'] : []));
            // Scalars read as typed ("default", 5, true); arrays as compact JSON.
            $params = array_map(
                fn ($p) => is_string($p) ? $p : (string) json_encode($p, JSON_UNESCAPED_UNICODE),
                array_values(is_array($input['params'] ?? null) ? $input['params'] : [])
            );
        } else {
            // Framework plumbing carries no information about what was done.
            unset($input['_token'], $input['_method']);
            $action = null;
            $fields = array_values(array_filter(array_map('strval', array_keys($input)), fn ($k) => $k !== 'page'));
            $params = [];
            if ($input === []) {
                return $empty;
            }
        }

        return [
            'action' => $action,
            'fields' => $fields,
            'params' => $params,
            'full' => (string) json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }
}
