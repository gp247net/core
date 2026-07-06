<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\Models\AdminCustomFieldDetail;

/**
 * Reusable admin-defined custom fields for admin-shell screens. GP247 lets admins
 * attach extra fields (text/select/checkbox/…) to entities (shop_customer,
 * shop_product, …); the values live in admin_custom_field_detail keyed by the
 * owning record + field type (the brownfield convention).
 *
 * A consuming Livewire component declares the entity type; this trait supplies the
 * `$customFields[code]` editing state plus load/init/rules/payload helpers. The
 * payload feeds the legacy `gp247_custom_field_update()` (or a mapping helper that
 * already persists `fields`), so persistence stays identical to the legacy
 * controllers. Reusable across packages (shop/front/plugin) — rule ui-tailadmin P3.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-004
 * @aidlc-adr ADR-005, ADR-007
 */
trait HasCustomFields
{
    /** @var array<string, mixed> Custom-field values keyed by field code (array for checkbox). */
    public array $customFields = [];

    /**
     * @return string The entity type the custom fields belong to (e.g. "shop_customer").
     */
    abstract protected function customFieldType(): string;

    /**
     * Active custom-field definitions for the type. Overridable (e.g. in tests) to
     * avoid a database dependency. Each item exposes code / name / option / required.
     *
     * @return iterable<mixed>
     */
    protected function customFieldDefs(): iterable
    {
        if (function_exists('gp247_custom_field_list')) {
            return gp247_custom_field_list($this->customFieldType());
        }

        return [];
    }

    /**
     * Reset custom-field state to empty values for every defined field (create mode).
     *
     * @return void
     */
    protected function initCustomFields(): void
    {
        $this->customFields = [];
        foreach ($this->customFieldDefs() as $field) {
            $this->customFields[$field->code] = $this->isCheckbox($field) ? [] : '';
        }
    }

    /**
     * Load custom-field values for an existing record into editing state.
     *
     * @param int|string $relId The owning record id.
     * @return void
     */
    protected function loadCustomFields($relId): void
    {
        $values = $this->customFieldValues($relId);

        $this->customFields = [];
        foreach ($this->customFieldDefs() as $field) {
            $raw = $values[$field->code] ?? '';
            $this->customFields[$field->code] = $this->isCheckbox($field)
                ? ($raw === '' ? [] : explode(',', (string) $raw))
                : (string) $raw;
        }
    }

    /**
     * Validation rules for required custom fields, keyed by component property path
     * (customFields.<code>). For screens whose persistence helper does not already
     * validate the fields; merge into the component rules().
     *
     * @return array<string, mixed>
     */
    protected function customFieldRules(): array
    {
        $rules = [];
        foreach ($this->customFieldDefs() as $field) {
            if (!empty($field->required)) {
                $rules['customFields.' . $field->code] = ['required'];
            }
        }

        return $rules;
    }

    /**
     * The custom-field values as a [code => value] payload for
     * gp247_custom_field_update() (or a data['fields'] slot).
     *
     * @return array<string, mixed>
     */
    protected function customFieldsPayload(): array
    {
        return $this->customFields;
    }

    /**
     * Stored custom-field values for a record, as [code => text].
     *
     * @param int|string $relId
     * @return array<string, string>
     */
    protected function customFieldValues($relId): array
    {
        $prefix = defined('GP247_DB_PREFIX') ? GP247_DB_PREFIX : '';

        return AdminCustomFieldDetail::query()
            ->join(
                $prefix . 'admin_custom_field',
                $prefix . 'admin_custom_field.id',
                $prefix . 'admin_custom_field_detail.custom_field_id',
            )
            ->where($prefix . 'admin_custom_field_detail.rel_id', $relId)
            ->where($prefix . 'admin_custom_field.type', $this->customFieldType())
            ->pluck($prefix . 'admin_custom_field_detail.text', $prefix . 'admin_custom_field.code')
            ->toArray();
    }

    /**
     * Whether a field definition renders as a (multi-value) checkbox group.
     *
     * @param mixed $field
     * @return bool
     */
    private function isCheckbox($field): bool
    {
        return ($field->option ?? '') === 'checkbox';
    }
}
