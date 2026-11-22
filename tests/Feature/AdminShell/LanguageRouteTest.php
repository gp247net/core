<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Tests\TestCase;

/**
 * Verifies the full-page admin route is registered inside the GP247 admin group
 * (prefix + ['web','admin'] middleware): an unauthenticated request is redirected
 * away by the admin Authenticate middleware (Layer-1 entry point, ADR-001).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-LW-001
 * @aidlc-adr ADR-001, ADR-002
 */
class LanguageRouteTest extends TestCase
{
    public function test_route_is_registered_under_admin_prefix(): void
    {
        $this->assertTrue(
            app('router')->has('admin_language.index'),
            'Expected the languages list route to be registered.',
        );
    }

    public function test_guest_is_redirected_from_admin_screen(): void
    {
        $url = route('admin_language.index');

        $response = $this->get($url);

        // Admin Authenticate middleware bounces guests to the admin login.
        $response->assertRedirect();
        $this->assertStringContainsString('login', $response->headers->get('Location'));
    }
}
