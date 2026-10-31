<?php

namespace GP247\Core\Api\Sync;

use Illuminate\Support\Facades\Auth;

/**
 * Where a change comes from — `api`, `admin`, `web` or `system` — and who made it, read
 * from the current request so the code recording an event never passes it by hand
 * (an ERP skips the echo of its own writes by source + actor).
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-record
 * @aidlc-adr api-sync_event-log-after-commit
 */
final class ApiEventSource
{
    /**
     * Source of a request path (null = no routed request: console, queue, scheduler).
     *
     * @param string|null $path        Request path without leading slash.
     * @param string      $adminPrefix Admin URL prefix.
     * @return string
     */
    public static function fromPath(?string $path, string $adminPrefix): string
    {
        if ($path === null) {
            return 'system';
        }
        $path = trim($path, '/');
        if ($path === 'api' || str_starts_with($path, 'api/')) {
            return 'api';
        }
        $adminPrefix = trim($adminPrefix, '/');
        if ($adminPrefix !== '' && ($path === $adminPrefix || str_starts_with($path, $adminPrefix . '/'))) {
            return 'admin';
        }
        return 'web';
    }

    /**
     * Source, actor type and actor id of the change being recorded now.
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    public static function current(): array
    {
        $request = app()->bound('request') ? request() : null;
        $path = $request && $request->route() ? $request->path() : null;
        $source = self::fromPath($path, (string) (defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : ''));
        // WHY: Livewire posts to livewire/* for both the admin and the storefront.
        if ($source === 'web' && str_starts_with((string) $path, 'livewire') && self::user('admin') !== null) {
            $source = 'admin';
        }

        $actor = match ($source) {
            'api'   => self::user('admin-api') ?? self::user('customer-api'),
            'admin' => self::user('admin'),
            'web'   => self::user('customer'),
            default => null,
        };
        if ($actor === null) {
            return [$source, null, null];
        }

        return [$source, $actor instanceof \GP247\Core\Models\AdminUser ? 'admin' : 'customer', (string) $actor->getKey()];
    }

    /**
     * @param string $guard
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    private static function user(string $guard)
    {
        try {
            return config('auth.guards.' . $guard) ? Auth::guard($guard)->user() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
