<?php
use GP247\Core\Admin\Models\AdminCustomField;
use GP247\Core\Admin\Models\AdminCustomFieldDetail;
use GP247\Core\Admin\Controllers\AdminCustomFieldController;
/**
 * Update custom field
 */
if (!function_exists('gp247_update_custom_field') && !in_array('gp247_update_custom_field', config('gp247_functions_except', []))) {
    function gp247_update_custom_field(array $fields, string $itemId, string $type)
    {
        $arrFields = array_keys((new AdminCustomFieldController)->fieldTypes());
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
