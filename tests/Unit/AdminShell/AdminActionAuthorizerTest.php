<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Domain\AdminActionAuthorizer;
use PHPUnit\Framework\TestCase;
use GP247\Core\Tests\Unit\AdminShell\Support\FakeAdminUser;

/**
 * Unit tests for the two-layer authorization decision core (ADR-001).
 *
 * Encodes the v1 URI+method RBAC semantics: administrator bypass, view.all may
 * read (GET) but never mutate (POST), access is granted by the screen path
 * against the user's http_uri catalog, and deny-by-default when the screen path
 * cannot be resolved.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-001
 * @aidlc-adr ADR-001
 */
class AdminActionAuthorizerTest extends TestCase
{
    private AdminActionAuthorizer $authorizer;

    protected function setUp(): void
    {
        $this->authorizer = new AdminActionAuthorizer();
    }

    public function test_administrator_is_allowed_even_for_mutating_action_without_uri(): void
    {
        $user = new FakeAdminUser(administrator: true);

        $decision = $this->authorizer->authorize($user, null, isMutating: true);

        $this->assertTrue($decision->isAllowed());
    }

    public function test_view_all_may_read(): void
    {
        $user = new FakeAdminUser(viewAll: true);

        $decision = $this->authorizer->authorize($user, 'gp247_admin/product', isMutating: false);

        $this->assertTrue($decision->isAllowed());
    }

    public function test_view_all_may_not_mutate(): void
    {
        $user = new FakeAdminUser(viewAll: true);

        $decision = $this->authorizer->authorize($user, 'gp247_admin/product', isMutating: true);

        $this->assertFalse($decision->isAllowed());
    }

    public function test_user_with_matching_uri_is_allowed(): void
    {
        $user = new FakeAdminUser(uris: ['gp247_admin/product']);

        $decision = $this->authorizer->authorize($user, 'gp247_admin/product', isMutating: true);

        $this->assertTrue($decision->isAllowed());
    }

    public function test_user_without_matching_uri_is_denied(): void
    {
        $user = new FakeAdminUser(uris: ['gp247_admin/order']);

        $decision = $this->authorizer->authorize($user, 'gp247_admin/product', isMutating: true);

        $this->assertFalse($decision->isAllowed());
    }

    public function test_unresolved_uri_is_denied_by_default(): void
    {
        $user = new FakeAdminUser(uris: ['gp247_admin/product']);

        $decision = $this->authorizer->authorize($user, null, isMutating: false);

        $this->assertFalse($decision->isAllowed());
        $this->assertNotSame('', $decision->reason());
    }
}
