<?php

use GP247\Core\Support\Password\PasswordPolicy;
use Illuminate\Validation\Rules\Password;

if (!function_exists('gp247_password_rule') && !in_array('gp247_password_rule', config('gp247_functions_except', []))) {
    /**
     * Validation rule of the password policy of an account group, as set on the
     * Password policy screen (callers add `required` / `nullable`, `string`, `confirmed`).
     *
     * @param string $audience Group key: admin, customer, or one a plugin registered.
     * @return Password
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-password-policy-single-source
     * @aidlc-adr admin-shell_password-policy-registry
     */
    function gp247_password_rule(string $audience): Password
    {
        return PasswordPolicy::for($audience)->rule();
    }
}

if (!function_exists('gp247_password_hash') && !in_array('gp247_password_hash', config('gp247_functions_except', []))) {
    /**
     * Hash an account password to store it — the one hashing call for every account
     * group (follows config/hashing.php of the site).
     *
     * @param string $plain
     * @return string
     *
     * @aidlc-unit admin-shell
     * @aidlc-story US-admin-shell-password-policy-single-source
     * @aidlc-adr admin-shell_password-policy-registry
     */
    function gp247_password_hash(string $plain): string
    {
        return PasswordPolicy::hash($plain);
    }
}
