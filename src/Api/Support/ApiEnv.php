<?php

namespace GP247\Core\Api\Support;

/**
 * Environment names of the REST API settings: correctly spelled names, with the old
 * misspelled ones still honoured so an existing .env keeps working. Used by the
 * package config file (env() is only valid there) and by gp247:doctor.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-config-naming
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiEnv
{
    /** New name => legacy name. */
    public const RENAMED = [
        'GP247_API_REMEMBER'          => 'GP247_API_RECOMMEMBER',
        'GP247_API_REMEMBER_ADMIN'    => 'GP247_API_RECOMMEMBER_ADMIN',
        'GP247_API_SCOPE_ADMIN_SUPER' => 'GP247_API_SCOPE_ADMIN_SUPPER',
    ];

    /**
     * Value of $new, else of $legacy, else $default.
     *
     * @param string $new
     * @param string $legacy
     * @param mixed  $default
     * @return mixed
     */
    public static function pick(string $new, string $legacy, $default)
    {
        $value = env($new);
        if ($value !== null) {
            return $value;
        }
        $value = env($legacy);

        return $value !== null ? $value : $default;
    }

    /**
     * Legacy names still set in the environment (reported by gp247:doctor).
     *
     * @return string[]
     */
    public static function legacyInUse(): array
    {
        return array_values(array_filter(self::RENAMED, fn ($legacy) => env($legacy) !== null));
    }
}
