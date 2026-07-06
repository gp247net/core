<?php

namespace GP247\Core\AdminShell\Infrastructure;

use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Shared registrar for plug-in packages (front, shop, plugins) that add modern
 * admin screens to the core admin shell. Centralises the registration mechanism
 * — Livewire class namespace + uniform list/create/edit routes inside the core
 * admin group — so each package declares only its resources instead of
 * reinventing the boilerplate (ADR-003/006/007, rule ui-tailadmin P3).
 *
 * View loading stays a provider concern (`loadViewsFrom`); this class handles the
 * Livewire namespace and routing, which is the part that was being duplicated.
 *
 * Routes land under GP247_ADMIN_PREFIX so they inherit admin auth + URI-based
 * RBAC (Layer-1) without touching core. Components are referenced by class-string
 * and only routed when the class exists, so screens light up as they ship
 * (strangler).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-LW-001
 * @aidlc-adr ADR-003, ADR-006, ADR-007
 */
class AdminShellResourceRegistrar
{
    /**
     * Register a package's Livewire namespace and resource routes.
     *
     * @param string $namespace      Livewire component namespace, e.g. "gp247-shop-admin"
     *                               (same string the package uses for its views).
     * @param string $classNamespace Livewire class namespace, e.g. "GP247\\Shop\\Admin\\Livewire".
     * @param string $routePrefix    Segment under the admin prefix, e.g. "shop-admin"
     *                               (also the route-name infix: "gp247.<prefix>.*").
     * @param array<string, array{0: class-string, 1?: class-string|null}> $resources
     *        Map of route base => [listComponent, formComponent?]. The form
     *        component is optional (list-only resources omit it).
     * @return void
     */
    public static function register(
        string $namespace,
        string $classNamespace,
        string $routePrefix,
        array $resources,
    ): void {
        Livewire::addNamespace($namespace, classNamespace: $classNamespace);

        // Guard: the GP247 admin constants are defined by the core package; skip
        // gracefully if a package boots before they are available.
        if (!defined('GP247_ADMIN_PREFIX') || !defined('GP247_ADMIN_MIDDLEWARE')) {
            return;
        }

        Route::prefix(GP247_ADMIN_PREFIX . '/' . $routePrefix)
            ->middleware(GP247_ADMIN_MIDDLEWARE)
            ->group(static function () use ($routePrefix, $resources): void {
                foreach ($resources as $base => $classes) {
                    $listClass = $classes[0] ?? null;
                    $formClass = $classes[1] ?? null;
                    $name = 'gp247.' . $routePrefix . '.' . $base;

                    if ($listClass !== null && class_exists($listClass)) {
                        Route::get($base, $listClass)->name($name);

                        // Two-panel managers (ResourcePanel) are a single full-page
                        // component but also expose edit/{id} so the edit state
                        // lives in the path (deep-link / refresh). Path order
                        // follows the GP247 convention (edit/{id}, not {id}/edit).
                        if ($formClass === null && is_subclass_of($listClass, ResourcePanel::class)) {
                            Route::get($base . '/edit/{id}', $listClass)->name($name . '.edit');
                        }
                    }
                    if ($formClass !== null && class_exists($formClass)) {
                        Route::get($base . '/create', $formClass)->name($name . '.create');
                        Route::get($base . '/edit/{id}', $formClass)->name($name . '.edit');
                    }
                }
            });
    }
}
