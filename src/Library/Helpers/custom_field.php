<?php
use GP247\Core\Models\AdminCustomField;
use GP247\Core\Models\AdminCustomFieldDetail;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

if (!function_exists('gp247_custom_field_get_tables') && !in_array('gp247_custom_field_get_tables', config('gp247_functions_except', []))) {
    /**
     * Get list of tables with prefix GP247_DB_PREFIX
     * @return array
     */
    function gp247_custom_field_get_tables(): array
    {
        //Customize table
        // WHY: explode(',', '') returns [''] (never a true empty array), so filter
        // blank entries first to correctly fall through to SHOW TABLES when unset.
        $tablesCustomize = array_filter(explode(',', config('gp247-config.admin.schema_customize')));
        if (!empty($tablesCustomize)) {
            return array_values($tablesCustomize);
        }
        try {
            $connection = GP247_DB_CONNECTION;
            $prefix = GP247_DB_PREFIX;
            
            switch(config("database.connections.$connection.driver")) {
                case 'mysql':
                    $query = "SHOW TABLES LIKE '$prefix%'";
                    break;
                case 'sqlite':
                    $query = "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '$prefix%'";
                    break;
                case 'pgsql':
                    $schema = config("database.connections.$connection.schema", 'public');
                    $query = "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname='$schema' AND tablename LIKE '$prefix%'";
                    break;
                case 'sqlsrv':
                    $query = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME LIKE '$prefix%'";
                    break;
                default:
                    return [];
            }

            $tables = DB::connection($connection)->select($query);
            return array_map(function($table) {
                $array = (array)$table;
                return array_shift($array);
            }, $tables);
            
        } catch (\Throwable $e) {
            gp247_handle_exception($e);
            return [];
        }
    }
}


/**
 * Update custom field
 */
if (!function_exists('gp247_custom_field_update') && !in_array('gp247_custom_field_update', config('gp247_functions_except', []))) {
    function gp247_custom_field_update(array $fields, string $itemId, string $type)
    {
        $arrFields = gp247_custom_field_get_tables();
        if (in_array($type, $arrFields) && !empty($fields)) {
            // WHY: the update is a destructive replace (delete-all then re-insert). Without a
            // transaction, a failure between the delete and the re-inserts leaves the entity
            // with partially-wiped custom-field values (RISK-TECH-custom-field-write-atomicity).
            DB::connection(GP247_DB_CONNECTION)->transaction(function () use ($fields, $itemId, $type) {
                (new AdminCustomFieldDetail)
                    ->join(GP247_DB_PREFIX.'admin_custom_field', GP247_DB_PREFIX.'admin_custom_field.id', GP247_DB_PREFIX.'admin_custom_field_detail.custom_field_id')
                    ->where(GP247_DB_PREFIX.'admin_custom_field_detail.rel_id', $itemId)
                    ->where(GP247_DB_PREFIX.'admin_custom_field.type', $type)
                    ->delete();

                // WHY: prefetch every definition for this type once (keyed by code) instead of
                // running one lookup query per submitted field inside the loop (removes N+1).
                $definitions = (new AdminCustomField)->where('type', $type)->get()->keyBy('code');
                foreach ($fields as $key => $value) {
                    $field = $definitions->get($key);
                    if ($field) {
                        $dataField = gp247_clean([
                            'custom_field_id' => $field->id,
                            'rel_id' => $itemId,
                            'text' => is_array($value) ? implode(',', $value) : trim($value),
                        ], [], true);
                        (new AdminCustomFieldDetail)->create($dataField);
                    }
                }
            });
        }
    }
}

// Function build validation rules for a single custom field definition
if (!function_exists('gp247_custom_field_rules') && !in_array('gp247_custom_field_rules', config('gp247_functions_except', []))) {
    /**
     * Map a custom-field definition to Laravel validation rule arrays keyed by suffix.
     *
     * Returns an associative array whose keys are the rule-key suffix appended to
     * `fields.<code>` ('' = the field itself, '.*' = each element of a checkbox array)
     * and whose values are arrays of Laravel rules.
     *
     * WHY typed rules: without server-side type checks a client can post `email`/`url`/
     * `date` fields with arbitrary junk, or a `checkbox`/`select`/`radio` with a value
     * outside its declared options — the latter is also how a comma could sneak into a
     * checkbox value and corrupt the CSV stored by gp247_custom_field_update()
     * (RISK-TECH-custom-field-typing). Choice types are pinned with Rule::in() to the
     * option keys declared on the definition, which closes both holes at once.
     *
     * WHY nullable-friendly: an optional field left blank must pass. Non-implicit format
     * rules (email/url/date/…) are skipped by Laravel on an empty value, and `nullable`
     * makes that explicit; only `required` fields force presence.
     *
     * @param  object  $field  A custom-field definition row (option, required, default).
     * @return array<string, array<int, mixed>>
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-custom-field-hardening
     * @aidlc-adr ADR-compat-foundation-custom-field-integrity
     */
    function gp247_custom_field_rules(object $field): array
    {
        $required = (int) $field->required === 1;
        $base = $required ? 'required' : 'nullable';

        // Option keys declared on choice types are stored as a JSON object in `default`.
        $optionKeys = [];
        if (in_array($field->option, ['select', 'radio', 'checkbox'], true)) {
            $decoded = json_decode((string) $field->default, true);
            if (is_array($decoded)) {
                // Keys are the stored values; cast to string so Rule::in matches submitted text.
                $optionKeys = array_map('strval', array_keys($decoded));
            }
        }

        switch ($field->option) {
            case 'email':
                return ['' => [$base, 'string', 'email', 'max:255']];
            case 'number':
                return ['' => [$base, 'numeric']];
            case 'url':
                return ['' => [$base, 'string', 'url', 'max:255']];
            case 'date':
                return ['' => [$base, 'date']];
            case 'month':
                return ['' => [$base, 'date_format:Y-m']];
            case 'week':
                return ['' => [$base, 'string', 'regex:/^\d{4}-W\d{2}$/']];
            case 'time':
                return ['' => [$base, 'date_format:H:i,H:i:s']];
            case 'color':
                return ['' => [$base, 'string', 'regex:/^#[0-9a-fA-F]{6}$/']];
            case 'select':
            case 'radio':
                $rules = [$base, 'string'];
                if ($optionKeys) {
                    $rules[] = Rule::in($optionKeys);
                }
                return ['' => $rules];
            case 'checkbox':
                // The field itself must be an array; each element must be a declared key.
                $each = ['string'];
                if ($optionKeys) {
                    $each[] = Rule::in($optionKeys);
                }
                return ['' => [$base, 'array'], '.*' => $each];
            default:
                // text, textarea, password and any unknown type: presence + string only.
                return ['' => [$base, 'string', 'max:65535']];
        }
    }
}

// Function validate custom field
if (!function_exists('gp247_custom_field_validate') && !in_array('gp247_custom_field_validate', config('gp247_functions_except', []))) {
    /**
     * Merge per-field validation rules for a given entity type into a rule set.
     *
     * @param  array  $arrValidation  Existing Laravel rules to merge into.
     * @param  string $type           Prefixed entity table name (e.g. shop_customer).
     * @return array
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-custom-field-hardening
     * @aidlc-adr ADR-compat-foundation-custom-field-integrity
     */
    function gp247_custom_field_validate(array $arrValidation, string $type)
    {
        //Custom fields
        $customFields = gp247_custom_field_list($type);
        if ($customFields) {
            foreach ($customFields as $field) {
                foreach (gp247_custom_field_rules($field) as $suffix => $rules) {
                    $arrValidation['fields.'.$field->code.$suffix] = $rules;
                }
            }
        }
        return $arrValidation;
    }
}

// Function get list custom field
if (!function_exists('gp247_custom_field_list') && !in_array('gp247_custom_field_list', config('gp247_functions_except', []))) {
    function gp247_custom_field_list(string $type)
    {
        return (new AdminCustomField)->where('type', $type)
        ->where('status', 1)
        ->get();
    }
}