<?php

namespace GP247\Core\AdminShell\Infrastructure;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Core\Models\AdminUser;

/**
 * Adapts the brownfield GP247\Core\Models\AdminUser model to AdminUserContract,
 * so the framework-free authorization core can run against the real RBAC data
 * without depending on Eloquent or the GP247 database directly.
 *
 * WHY: the existing AdminUser::can() already honors administrator bypass and
 * resolves slugs across roles and direct grants — this adapter reuses it
 * verbatim rather than re-implementing RBAC (refactor-incremental, NFR-MAINT-001).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
final class EloquentAdminUserAdapter implements AdminUserContract
{
    /**
     * @param AdminUser $user The authenticated admin user model.
     */
    public function __construct(private AdminUser $user)
    {
    }

    public function isAdministrator(): bool
    {
        return $this->user->isAdministrator();
    }

    public function isViewAll(): bool
    {
        return $this->user->isViewAll();
    }

    public function can(string $slug): bool
    {
        return $this->user->can($slug);
    }
}
