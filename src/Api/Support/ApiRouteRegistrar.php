<?php

namespace GP247\Core\Api\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Seam through which every package (core, front, shop) and plugin registers its
 * REST API route files. The file is wrapped in the shared API middleware group
 * (JSON, API Connection, rate limit), so a package or plugin cannot forget it. The
 * {error, msg, code} error body applies to routes under the GP247_API_* prefixes
 * (api/core, api/front, api/v2/core, api/v2/front); a plugin using its own prefix keeps
 * Laravel's default error bodies.
 *
 * Nothing is registered when API mode is off, when routes are cached (the cache
 * already holds them), or when the owning package is not installed at the database
 * level (its create-tables migration never ran) — a half-installed package then
 * answers 404 instead of a 500 on a missing table.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-package-route-registration
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiRouteRegistrar
{
    /** @var array<string, bool> Memo of migration-name checks for this request. */
    private static array $installed = [];

    /**
     * Register a route file under the shared API middleware.
     *
     * @param string      $routeFile        Absolute path of the route file (it sets its own prefix).
     * @param string|null $installMigration Name of the package's create-tables migration; the routes
     *                                      are registered only once it has run. Null = always.
     * @return void
     */
    public static function register(string $routeFile, ?string $installMigration = null): void
    {
        if (!config('gp247-config.env.GP247_API_MODE') || app()->routesAreCached() || !is_file($routeFile)) {
            return;
        }
        if ($installMigration !== null && !self::migrationRan($installMigration)) {
            return;
        }

        Route::middleware(GP247_API_MIDDLEWARE)->group($routeFile);
    }

    /**
     * Whether a migration is recorded as run (false when the database is unavailable).
     *
     * @param string $migration
     * @return bool
     */
    private static function migrationRan(string $migration): bool
    {
        if (!empty(self::$installed[$migration])) {
            return true;
        }
        try {
            $ran = DB::connection(GP247_DB_CONNECTION)
                ->table(config('database.migrations.table', 'migrations'))
                ->where('migration', $migration)->exists();
        } catch (\Throwable $e) {
            $ran = false;
        }
        // WHY only a positive memo: a long-running worker (Octane, PHPUnit) must see a
        // package installed after its first boot.
        if ($ran) {
            self::$installed[$migration] = true;
        }
        return $ran;
    }
}
