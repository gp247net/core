<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Application\PermissionResolver;
use GP247\Core\AdminShell\Domain\AdminActionAuthorizer;
use GP247\Core\AdminShell\Domain\AuthorizationException;
use PHPUnit\Framework\TestCase;
use GP247\Core\Tests\Unit\AdminShell\Support\FakeAdminUser;

/**
 * Edge-case coverage for the authorization core: blank map values, irregular
 * component names, action-suffix-only names, and the deny-reason carrier.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
class EdgeCaseTest extends TestCase
{
    public function test_blank_map_value_falls_back_to_convention(): void
    {
        $resolver = new PermissionResolver(map: ['gp247-core::user-list' => '   ']);

        $this->assertSame('admin_user', $resolver->resolve('gp247-core::user-list')?->value());
    }

    public function test_component_that_is_only_an_action_suffix_resolves_to_that_word(): void
    {
        // WHY: with a single segment there is no action to strip, so "list"
        // itself becomes the resource — better an explicit (likely-denied) key
        // than null silently widening access.
        $resolver = new PermissionResolver();

        $this->assertSame('admin_list', $resolver->resolve('gp247-core::list')?->value());
    }

    public function test_custom_convention_prefix_is_applied(): void
    {
        $resolver = new PermissionResolver(conventionPrefix: 'perm_');

        $this->assertSame('perm_user', $resolver->resolve('gp247-core::user-list')?->value());
    }

    public function test_regular_user_without_view_all_cannot_read_unresolved_screen(): void
    {
        $authorizer = new AdminActionAuthorizer();

        $decision = $authorizer->authorize(new FakeAdminUser(uris: ['gp247_admin/user']), null, isMutating: false);

        $this->assertFalse($decision->isAllowed());
    }

    public function test_authorization_exception_carries_reason(): void
    {
        $exception = AuthorizationException::fromReason('missing_permission_uri');

        $this->assertSame('missing_permission_uri', $exception->getMessage());
    }
}
