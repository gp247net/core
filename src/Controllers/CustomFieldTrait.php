<?php

namespace GP247\Core\Controllers;

use Illuminate\Support\Facades\Validator;

trait CustomFieldTrait
{
    /**
     * Get custom field validation rules
     *
     * @param array $arrValidation
     * @param string $modelClass
     * @return array
     */
    protected function getCustomFieldValidation(array $arrValidation, $modelClass)
    {
        return gp247_custom_field_validate($arrValidation, (new $modelClass)->getTable());
    }

    /**
     * Handle custom field validation
     *
     * @param array $data
     * @param array $arrValidation
     * @param array $customMessages
     * @return \Illuminate\Validation\Validator
     */
    protected function validateWithCustomFields(array $data, array $arrValidation, array $customMessages = [])
    {
        return Validator::make($data, $arrValidation, $customMessages);
    }

    /**
     * Update custom fields for a model
     *
     * @param array $fields
     * @param int|string $itemId
     * @param string $modelClass
     * @return void
     */
    protected function updateCustomFields(array $fields, $itemId, $modelClass)
    {
        gp247_custom_field_update($fields, $itemId, (new $modelClass)->getTable());
    }

} 