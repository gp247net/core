<?php

namespace GP247\Core\Support\Password;

/**
 * Registry of the account groups shown on the Password policy screen.
 *
 * Core registers `admin`; a package or plugin registers its own group (shop: `customer`,
 * MultiVendor: `vendor`) so core never needs to know them (NFR-MAINT-001) — same shape as
 * AdminAlertRegistry and SettingsHubTabRegistry. A later registration with the same key
 * replaces the earlier one.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-policy-audiences
 * @aidlc-adr admin-shell_password-policy-registry
 */
final class PasswordPolicyRegistry
{
    /** @var array<string, PasswordAudience> */
    private static array $audiences = [];

    /**
     * Register an account group.
     *
     * @param PasswordAudience $audience
     * @return void
     */
    public static function register(PasswordAudience $audience): void
    {
        // WHY unset first: a replacement keeps its own registration position at the end.
        unset(self::$audiences[$audience->key]);
        self::$audiences[$audience->key] = $audience;
    }

    /**
     * Registered groups in registration order, keyed by group key.
     *
     * @return array<string, PasswordAudience>
     */
    public static function all(): array
    {
        return self::$audiences;
    }

    /**
     * One registered group, or null.
     *
     * @param string $key
     * @return PasswordAudience|null
     */
    public static function get(string $key): ?PasswordAudience
    {
        return self::$audiences[$key] ?? null;
    }

    /**
     * Remove one group (a plugin turned off, tests).
     *
     * @param string $key
     * @return void
     */
    public static function forget(string $key): void
    {
        unset(self::$audiences[$key]);
    }
}
