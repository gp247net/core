<?php

namespace GP247\Core\Backup\Infrastructure\Site;

use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Laravel's own maintenance mode for a full-database restore (ADR shadow-table-swap §6): the
 * storefront, the admin and the API answer 503 while tables are replaced, except for the browser
 * of the person restoring, who holds the bypass cookie of the secret.
 *
 * Ownership is read from the maintenance data itself (the secret and a "restore until" time are
 * stored in it): a restore turns off only the maintenance it turned on — a site that was already
 * down stays down — and a maintenance left behind by a restore that can no longer finish is
 * recognised and released (shared hosts have no command line for `php artisan up`).
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-full-database
 * @aidlc-adr backup-restore_shadow-table-swap
 */
class MaintenanceGuard
{
    public const MARKER = 'gp247_restore_until';
    private const RETRY_SECONDS = 60;

    /**
     * @return bool The site is in maintenance mode.
     */
    public function isActive(): bool
    {
        return app()->maintenanceMode()->active();
    }

    /**
     * @param string $secret Bypass secret (random, kept in the encrypted job cursor).
     * @param int    $until  Unix time after which the restore cannot continue (its data key expired).
     * @return void
     */
    public function activate(#[\SensitiveParameter] string $secret, int $until): void
    {
        app()->maintenanceMode()->activate([
            'except' => [],
            'redirect' => null,
            'retry' => self::RETRY_SECONDS,
            'refresh' => null,
            'secret' => $secret,
            'status' => 503,
            'template' => null,
            self::MARKER => $until,
        ]);
    }

    /**
     * @param string $secret
     * @return bool The current maintenance was turned on by the restore holding this secret.
     */
    public function isOurs(#[\SensitiveParameter] string $secret): bool
    {
        if (!$this->isActive()) {
            return false;
        }
        $data = app()->maintenanceMode()->data();

        return is_string($data['secret'] ?? null) && hash_equals($data['secret'], $secret);
    }

    /**
     * @return int|null "Restore until" time of the current maintenance, null when it is not a restore's.
     */
    public function restoreUntil(): ?int
    {
        if (!$this->isActive()) {
            return null;
        }
        $until = app()->maintenanceMode()->data()[self::MARKER] ?? null;

        return is_int($until) ? $until : null;
    }

    /**
     * @return void
     */
    public function deactivate(): void
    {
        app()->maintenanceMode()->deactivate();
    }

    /**
     * @param string $secret
     * @return Cookie The cookie that lets one browser through (set by the admin screen, BK-4).
     */
    public function bypassCookie(#[\SensitiveParameter] string $secret): Cookie
    {
        return MaintenanceModeBypassCookie::create($secret);
    }

    /**
     * @return string New random bypass secret.
     */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(16));
    }
}
