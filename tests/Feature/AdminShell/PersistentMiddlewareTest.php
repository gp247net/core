<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Middleware\Authenticate;
use GP247\Core\Middleware\PermissionMiddleware;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use GP247\Core\Tests\TestCase;

/**
 * Verifies the deploy wiring (Layer-1): the existing GP247 admin RBAC middleware
 * is registered as Livewire persistent middleware, so it is re-applied on
 * "livewire/update" requests against the original admin page route (ADR-001).
 *
 * End-to-end enforcement (real admin page → update re-checks URI permission) is
 * validated against seeded RBAC data in a live admin environment; this test
 * asserts the registration that makes it happen.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
class PersistentMiddlewareTest extends TestCase
{
    public function test_admin_auth_is_persisted_across_livewire_updates(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(Authenticate::class, $persistent);
    }

    public function test_admin_permission_is_persisted_across_livewire_updates(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(PermissionMiddleware::class, $persistent);
    }
}
