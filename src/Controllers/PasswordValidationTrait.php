<?php

namespace GP247\Core\Controllers;

use GP247\Core\Support\Password\PasswordPolicy;

/**
 * Administrator password rules for the screens that set one (Users, own profile), taken
 * from the administrator block of the Password policy screen.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-policy-single-source
 * @aidlc-adr admin-shell_password-policy-registry
 */
trait PasswordValidationTrait
{
    /**
     * Rule object of the administrator password policy.
     *
     * @return \Illuminate\Validation\Rules\Password
     */
    protected function rule()
    {
        return PasswordPolicy::for('admin')->rule();
    }

    /**
     * @return array<int, mixed> Rules of a required password.
     */
    protected function rulePassword()
    {
        return ['required', 'string', $this->rule()];
    }

    /**
     * @return array<int, mixed> Rules of a required, confirmed password.
     */
    protected function rulePasswordConfirm()
    {
        return ['required', 'string', $this->rule(), 'confirmed'];
    }

    /**
     * @return array<int, mixed> Rules of an optional password (empty keeps the current one).
     */
    protected function rulePasswordNullable()
    {
        return ['nullable', 'string', $this->rule()];
    }
}
