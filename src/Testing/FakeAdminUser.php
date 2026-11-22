<?php

namespace GP247\Core\Testing;

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
 * Shipped in the package (not in tests/) because the test suites of other packages
 * (front, shop, mcp) and of plugins need it, and tests/ is not part of the Composer
 * package. Inert at runtime: nothing in the application uses it.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-public-test-suite
 * @aidlc-story US-RBAC-001
 * @aidlc-adr compat-foundation_public-test-placement
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

    /**
     * @return bool Whether the fake holds the administrator role.
     */
    public function isAdministrator(): bool
    {
        return $this->administrator;
    }

    /**
     * @return bool Whether the fake holds the view.all role.
     */
    public function isViewAll(): bool
    {
        return $this->viewAll;
    }

    /**
     * Retained for interface compatibility; the slug is only a label under the
     * URI+method model, so it never grants access on its own.
     *
     * @param string $slug Permission slug (ignored).
     * @return bool Always false.
     */
    public function can(string $slug): bool
    {
        return false;
    }

    /**
     * Grant a path at or under one of the granted prefixes (any method).
     *
     * @param string $path   Admin path, with or without surrounding slashes.
     * @param string $method HTTP method (not used by the fake).
     * @return bool Whether access is granted.
     */
    public function canAccessUrl(string $path, string $method): bool
    {
        if ($this->administrator) {
            return true;
        }

        $path = trim($path, '/');

        foreach ($this->uris as $granted) {
            $granted = trim($granted, '/');
            if ($granted === '') {
                continue;
            }
            if ($path === $granted || str_starts_with($path, $granted . '/')) {
                return true;
            }
        }

        return false;
    }
}
