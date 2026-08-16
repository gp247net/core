<?php

namespace GP247\Core\AdminShell\Domain;

/**
 * Abstraction over the authenticated admin user, scoped to the data the
 * authorization core needs.
 *
 * Mirrors the relevant surface of GP247\Core\Models\AdminUser so the decision
 * logic stays framework- and database-free (and therefore unit-testable). The
 * real model is bound through an adapter in the Infrastructure layer.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
interface AdminUserContract
{
    /**
     * Whether the user holds the "administrator" role (full bypass).
     *
     * @return bool True when the user may perform any admin action.
     */
    public function isAdministrator(): bool;

    /**
     * Whether the user holds the "view.all" role (read-only everywhere).
     *
     * @return bool True when the user may read any screen but mutate nothing.
     */
    public function isViewAll(): bool;

    /**
     * Whether the user has been granted the given permission slug.
     *
     * @param string $slug Permission slug (e.g. "admin_product").
     * @return bool True when the user (via roles or direct grants) has the slug.
     */
    public function can(string $slug): bool;

    /**
     * Whether the user may access the given admin path with the given HTTP method,
     * evaluated against their permissions' `http_uri` entries (v1 URI+method model).
     *
     * WHY: the admin shell authorizes screens/actions by the same `http_uri`
     * catalog the menu and PermissionMiddleware use — the permission slug is only
     * a label, access is decided by URI + method (ADR-001 Layer-2, v1 semantics).
     *
     * @param string $path   Admin path without scheme/host (e.g. "gp247_admin/order").
     * @param string $method HTTP method the action maps to ("GET" for view, "POST" for mutation).
     * @return bool True when a granted permission covers the path for that method.
     */
    public function canAccessUrl(string $path, string $method): bool;
}
