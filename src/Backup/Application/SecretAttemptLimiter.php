<?php

namespace GP247\Core\Backup\Application;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Limits wrong backup passwords / recovery keys typed on the admin screens (mod 20261110T164834,
 * D4): without it an admin session could try passwords against an archive without end, each try
 * costing two PBKDF2 runs of 600 000 rounds on the host. Per admin and for the whole site (several
 * accounts cannot add up their tries). The command line is not limited: it already needs the server.
 *
 * @aidlc-unit backup-admin
 * @aidlc-story US-backup-admin-step-up-auth
 * @aidlc-adr backup-admin_step-up-and-signed-download
 */
final class SecretAttemptLimiter
{
    public const PER_ADMIN = 5;
    public const PER_ADMIN_SECONDS = 900;
    public const PER_SITE = 20;
    public const PER_SITE_SECONDS = 3600;
    public const REASON = 'secret_throttled';

    /**
     * Count a try BEFORE the secret is checked: the increment is atomic, so requests sent in parallel
     * cannot all pass a "too many?" check made before any of them failed.
     *
     * @param string $adminId
     * @return int|null Seconds to wait before the next try, null when this try may go on.
     */
    public static function begin(string $adminId): ?int
    {
        if (RateLimiter::tooManyAttempts(self::siteKey(), self::PER_SITE)) {
            return max(1, RateLimiter::availableIn(self::siteKey()));
        }
        $key = self::adminKey($adminId);
        if (RateLimiter::hit($key, self::PER_ADMIN_SECONDS) > self::PER_ADMIN) {
            return max(1, RateLimiter::availableIn($key));
        }

        return null;
    }

    /**
     * The secret was wrong: it also counts for the whole site (several accounts cannot add up their tries).
     *
     * @param string $adminId
     * @return void
     */
    public static function failed(string $adminId): void
    {
        RateLimiter::hit(self::siteKey(), self::PER_SITE_SECONDS);
    }

    /**
     * The right secret was typed: this admin's count starts again (the site count runs out by itself).
     *
     * @param string $adminId
     * @return void
     */
    public static function clear(string $adminId): void
    {
        RateLimiter::clear(self::adminKey($adminId));
    }

    /**
     * @param string $adminId
     * @return string
     */
    private static function adminKey(string $adminId): string
    {
        return 'gp247-backup-secret:admin:' . $adminId;
    }

    /**
     * @return string
     */
    private static function siteKey(): string
    {
        return 'gp247-backup-secret:site';
    }
}
