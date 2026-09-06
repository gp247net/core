<?php

namespace GP247\Core\AdminShell\Support;

/**
 * Registry seam that lets other packages (shop, plugins) contribute extra tabs to
 * the core Configuration hub (SettingsHub) WITHOUT the core referencing them.
 *
 * The core hub renders its own tabs (General/Email/Custom) plus every tab
 * registered here; a contributor calls register() from its service provider boot
 * (typically guarded by its own "installed" check). This keeps core independent of
 * shop/plugins (NFR-MAINT-core-independent-of-shop) — the hub only ever sees an
 * opaque {key,label,component,authUri} tuple, never a shop class.
 *
 * Storage is a static keyed map so registration is idempotent (same key overwrites)
 * and cheap; a page render simply reads all(). Per-tab authorization is decided by
 * the hub against `authUri`, not here.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-admin-shell-config-hub-tab-registry
 * @aidlc-adr admin-shell_config-hub-tab-registry
 */
final class SettingsHubTabRegistry
{
    /**
     * Registered tabs, keyed by tab key.
     *
     * @var array<string, array{key:string, label:string, component:string, authUri:string, order:int}>
     */
    private static array $tabs = [];

    /**
     * Register (or replace) a contributed configuration tab.
     *
     * @param string $key       Stable tab key (Alpine tab id, e.g. "shop").
     * @param string $label     Language code (or literal) resolved via gp247_language_render at render.
     * @param string $component Livewire component name to embed (e.g. "gp247-shop-admin::shop-config-form").
     * @param string $authUri   Admin path gating the tab (e.g. "gp247_admin/shop_config"); checked per-viewer.
     * @param int    $order     Sort order among contributed tabs (ascending; default 100).
     * @return void
     */
    public static function register(string $key, string $label, string $component, string $authUri, int $order = 100): void
    {
        self::$tabs[$key] = [
            'key' => $key,
            'label' => $label,
            'component' => $component,
            'authUri' => $authUri,
            'order' => $order,
        ];
    }

    /**
     * All contributed tabs, ordered by `order` then `key` (deterministic).
     *
     * @return array<int, array{key:string, label:string, component:string, authUri:string, order:int}>
     */
    public static function all(): array
    {
        $tabs = array_values(self::$tabs);
        usort(
            $tabs,
            static fn (array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']],
        );

        return $tabs;
    }

    /**
     * Clear the registry. Primarily for tests and re-registration; production code
     * registers once per boot.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$tabs = [];
    }
}
