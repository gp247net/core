<?php

namespace GP247\Core\AdminShell\Http\Livewire;

use GP247\Core\AdminShell\Infrastructure\DataTableComponent;
use GP247\Core\Models\AdminCustomField;

/**
 * Admin list of custom fields (ADR-001/002/005). Gated by `admin_custom_field`.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-001, ADR-002, ADR-005
 */
class CustomFieldList extends DataTableComponent
{
    protected ?string $permission = 'admin_custom_field';

    protected ?string $titleKey = 'admin.custom_field.title';

    /**
     * @return AdminCustomField
     */
    protected function query()
    {
        return new AdminCustomField();
    }

    /**
     * @return array<string, string>
     */
    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'Name'];
    }

    /**
     * @return array<int, string>
     */
    protected function searchable(): array
    {
        return ['name', 'code'];
    }

    /**
     * @return string
     */
    protected function listView(): string
    {
        return 'gp247-admin::livewire.custom-field-list';
    }

    /**
     * @return array<string, mixed>
     */
    protected function viewData(): array
    {
        return [
            'tables' => function_exists('gp247_custom_field_get_tables') ? gp247_custom_field_get_tables() : [],
        ];
    }
}
