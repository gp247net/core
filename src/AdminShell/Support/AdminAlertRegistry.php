<?php

namespace GP247\Core\AdminShell\Support;

use GP247\Core\AdminShell\Domain\AdminAlertCheck;
use InvalidArgumentException;

/**
 * Registry of the checks shown in the "System alerts" dashboard block.
 *
 * Core registers its own checks here exactly like a package or plugin would
 * (same shape as SettingsHubTabRegistry), so core never needs to know a
 * contributor's class (NFR-MAINT-001). A later registration with the same key
 * replaces the earlier one.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-alert-check-registry
 * @aidlc-adr admin-shell_system-alert-registry
 */
final class AdminAlertRegistry
{
    /** @var array<string, class-string<AdminAlertCheck>> */
    private static array $checks = [];

    /**
     * Register a check class.
     *
     * @param string $checkClass Class implementing AdminAlertCheck.
     * @return void
     * @throws InvalidArgumentException When the class does not implement AdminAlertCheck.
     */
    public static function register(string $checkClass): void
    {
        if (!is_subclass_of($checkClass, AdminAlertCheck::class)) {
            throw new InvalidArgumentException($checkClass . ' must implement ' . AdminAlertCheck::class);
        }

        $key = app($checkClass)->key();
        // WHY unset first: a replacement keeps its own registration position at the end.
        unset(self::$checks[$key]);
        self::$checks[$key] = $checkClass;
    }

    /**
     * Registered checks in registration order, keyed by check key.
     *
     * @return array<string, class-string<AdminAlertCheck>>
     */
    public static function all(): array
    {
        return self::$checks;
    }

    /**
     * Forget every registration (tests).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$checks = [];
    }
}
