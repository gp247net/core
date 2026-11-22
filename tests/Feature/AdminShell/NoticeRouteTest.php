<?php

namespace GP247\Core\Tests\Feature\AdminShell;

use GP247\Core\Tests\TestCase;

/**
 * Verifies the notifications full-page route is registered inside the GP247
 * admin group (prefix + ['web','admin']): an unauthenticated request is bounced
 * by the admin Authenticate middleware (Layer-1 entry point, ADR-001).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-008
 * @aidlc-adr ADR-001, ADR-002
 */
class NoticeRouteTest extends TestCase
{
    public function test_route_is_registered_under_admin_prefix(): void
    {
        $this->assertTrue(
            app('router')->has('admin_notice.index'),
            'Expected the notices list route to be registered.',
        );
    }

    public function test_guest_is_redirected_from_admin_screen(): void
    {
        $url = route('admin_notice.index');

        $response = $this->get($url);

        $response->assertRedirect();
        $this->assertStringContainsString('login', $response->headers->get('Location'));
    }
}
