<?php
use GP247\Core\Models\AdminCustomField;
use GP247\Core\Models\AdminCustomFieldDetail;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (!function_exists('gp247_custom_field_get_tables') && !in_array('gp247_custom_field_get_tables', config('gp247_functions_except', []))) {
    /**
     * Get list of tables with prefix GP247_DB_PREFIX
     * @return array
     */
    function gp247_custom_field_get_tables(): array
    {
        //Customize table
        $tablesCustomize = explode(',', config('gp247-config.admin.schema_customize'));
        if (!empty($tablesCustomize)) {
            return $tablesCustomize;
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
            (new AdminCustomFieldDetail)
                ->join(GP247_DB_PREFIX.'admin_custom_field', GP247_DB_PREFIX.'admin_custom_field.id', GP247_DB_PREFIX.'admin_custom_field_detail.custom_field_id')
                ->where(GP247_DB_PREFIX.'admin_custom_field_detail.rel_id', $itemId)
                ->where(GP247_DB_PREFIX.'admin_custom_field.type', $type)
                ->delete();

            $dataField = [];
            foreach ($fields as $key => $value) {
                $field = (new AdminCustomField)->where('code', $key)->where('type', $type)->first();
                if ($field) {
                    $dataField = gp247_clean([
                        'custom_field_id' => $field->id,
                        'rel_id' => $itemId,
                        'text' => is_array($value) ? implode(',', $value) : trim($value),
                    ], [], true);
                    (new AdminCustomFieldDetail)->create($dataField);
                }
            }
        }
    }
}

// Function validate custom field
if (!function_exists('gp247_custom_field_validate') && !in_array('gp247_custom_field_validate', config('gp247_functions_except', []))) {
    function gp247_custom_field_validate(array $arrValidation, string $type)
    {
        //Custom fields
        $customFields = gp247_custom_field_list($type);
        if ($customFields) {
            foreach ($customFields as $field) {
                if ($field->required) {
                    $arrValidation['fields.'.$field->code] = 'required';
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