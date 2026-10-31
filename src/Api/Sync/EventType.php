<?php

namespace GP247\Core\Api\Sync;

use GP247\Core\AdminShell\Application\AdminRouteAccess;
use GP247\Core\Models\AdminUser;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Registry of event types (`gp247-config.api.events`): `type => ['fields' => [...],
 * 'permission' => '<admin route name>']`. Core, shop and plugins register their types;
 * a type name is `<resource>.<action>` in lower case, plugins prefix theirs. Only the
 * declared fields are ever stored, and an admin reads a type only when allowed to open
 * the screen of its permission route.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-event-record, US-api-sync-event-read
 * @aidlc-adr api-sync_event-log-after-commit
 */
final class EventType
{
    /**
     * Registered types with a valid name.
     *
     * @return array<string, array{fields: string[], permission: string}>
     */
    public static function all(): array
    {
        $out = [];
        foreach ((array) config('gp247-config.api.events', []) as $type => $definition) {
            if (is_string($type) && self::isValidName($type) && is_array($definition)) {
                $out[$type] = [
                    'fields'     => array_values(array_filter((array) ($definition['fields'] ?? []), 'is_string')),
                    'permission' => (string) ($definition['permission'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /**
     * @param string $type
     * @return array{fields: string[], permission: string}|null
     */
    public static function definition(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * `<resource>.<action>` in lower case (more segments allowed for plugins), ≤ 64 chars.
     *
     * @param string $type
     * @return bool
     */
    public static function isValidName(string $type): bool
    {
        return strlen($type) <= 64 && preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $type) === 1;
    }

    /**
     * The declared fields of $data, in declaration order.
     *
     * @param string[] $fields
     * @param array    $data
     * @return array
     */
    public static function keep(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field];
            }
        }
        return $out;
    }

    /**
     * Types $user may read: those whose permission route the user may open (GET).
     *
     * @param AdminUser $user
     * @return string[]
     */
    public static function readableBy(AdminUser $user): array
    {
        $types = [];
        foreach (self::all() as $type => $definition) {
            $route = self::permissionRoute($definition['permission']);
            if ($route !== null && AdminRouteAccess::allows($user, 'GET', $route, self::samplePath($route))) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * First registered permission route $user may open, or null.
     *
     * @param AdminUser $user
     * @return Route|null
     */
    public static function firstReadableRoute(AdminUser $user): ?Route
    {
        foreach (self::all() as $definition) {
            $route = self::permissionRoute($definition['permission']);
            if ($route !== null && AdminRouteAccess::allows($user, 'GET', $route, self::samplePath($route))) {
                return $route;
            }
        }
        return null;
    }

    /**
     * @param string $name
     * @return Route|null
     */
    private static function permissionRoute(string $name): ?Route
    {
        $route = $name !== '' ? RouteFacade::getRoutes()->getByName($name) : null;
        return $route instanceof Route ? $route : null;
    }

    /**
     * Concrete path of $route with placeholder parameters (as ApiAdminAuthorize does).
     *
     * @param Route $route
     * @return string
     */
    private static function samplePath(Route $route): string
    {
        return preg_replace('/\{[^}]+\}/', '0', $route->uri());
    }
}
