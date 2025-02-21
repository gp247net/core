<?php

namespace GP247\Core\Controllers;

trait PasswordValidationTrait
{
    /**
     * Get password validation rules
     * @return array
     */
    protected function rule()
    {
        $passwordValidate = \Illuminate\Validation\Rules\Password::min(gp247_config_global('admin_password_min', 6));
        if (gp247_config('admin_password_letter')) {
            // Require at least one letter...
            $passwordValidate = $passwordValidate->letters();
        }
        if (gp247_config('admin_password_mixedcase')) {
            // Require at least one uppercase and one lowercase letter...
            $passwordValidate = $passwordValidate->mixedCase();
        }
        if (gp247_config('admin_password_number')) {
            // Require at least one number...
            $passwordValidate = $passwordValidate->numbers();
        }
        if (gp247_config('admin_password_symbol')) {
            // Require at least one symbol...
            $passwordValidate = $passwordValidate->symbols();
        }
        if (gp247_config('admin_password_max')) {
            $passwordValidate = $passwordValidate->max(gp247_config_global('admin_password_max'));
        }
        return $passwordValidate;
        return [
            'password'          => ['required','string', $passwordValidate],
            'password_confirm'  => ['required','string', $passwordValidate,'confirmed'],
            'password_nullable' => ['nullable','string', $passwordValidate],
        ];
    }

    protected function rulePassword() {
        $passwordValidate =  $this->rule();
        return ['required','string', $passwordValidate];
    }

    protected function rulePasswordConfirm() {
        $passwordValidate =  $this->rule();
        return ['required','string', $passwordValidate,'confirmed'];
    }

    protected function rulePasswordNullable() {
        $passwordValidate =  $this->rule();
        return ['nullable','string', $passwordValidate];
    }
} 