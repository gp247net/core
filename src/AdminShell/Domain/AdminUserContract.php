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
}
