<?php

namespace GP247\Core\Tests\Unit\AdminShell\Support;

use GP247\Core\AdminShell\Domain\AdminUserContract;

/**
 * In-memory test double for AdminUserContract.
 *
 * Lets the pure-PHP authorization core be exercised without booting Laravel,
 * the GP247 database, or the real Eloquent AdminUser model. Access is decided by
 * the v1 URI model (ADR-001 Layer-2): the fake is granted a set of admin path
 * prefixes and grants any path at or under one of them (both GET and POST — the
 * view.all read-only distinction is enforced by the authorizer, not here).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-001
 */
final class FakeAdminUser implements AdminUserContract
{
    /**
     * @param bool     $administrator Whether the user has the administrator role.
     * @param bool     $viewAll       Whether the user has the view.all role.
     * @param string[] $uris          Granted admin path prefixes (e.g. ["gp247_admin/order"]).
     */
    public function __construct(
        private bool $administrator = false,
        private bool $viewAll = false,
        private array $uris = [],
    ) {
    }

    public function isAdministrator(): bool
    {
        return $this->administrator;
    }

    public function isViewAll(): bool
    {
        return $this->viewAll;
    }

    /**
     * Retained for interface compatibility; the slug is only a label under the
     * URI+method model, so it never grants access on its own.
     */
    public function can(string $slug): bool
    {
        return false;
    }

    public function canAccessUrl(string $path, string $method): bool
    {
        if ($this->administrator) {
            return true;
        }

        $path = trim($path, '/');

        foreach ($this->uris as $granted) {
            $granted = trim($granted, '/');
            if ($granted === '' ) {
                continue;
            }
            if ($path === $granted || str_starts_with($path, $granted . '/')) {
                return true;
            }
        }

        return false;
    }
}
