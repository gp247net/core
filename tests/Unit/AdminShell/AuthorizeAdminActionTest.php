<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Application\AuthorizeAdminAction;
use GP247\Core\AdminShell\Application\PermissionResolver;
use GP247\Core\AdminShell\Domain\AdminActionAuthorizer;
use PHPUnit\Framework\TestCase;
use GP247\Core\Tests\Unit\AdminShell\Support\FakeAdminUser;

/**
 * Unit tests for the orchestration use case: classify the action as read/mutation
 * and apply the URI+method authorization core against the screen path (ADR-001
 * Layer-2, v1 semantics).
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
class AuthorizeAdminActionTest extends TestCase
{
    private function useCase(): AuthorizeAdminAction
    {
        return new AuthorizeAdminAction(new PermissionResolver(), new AdminActionAuthorizer());
    }

    /**
     * Use case with an explicit veto fence (ADR admin-shell_action-fence-seam).
     *
     * @param callable $fence (user, ?screenUri, action): ?string
     */
    private function fencedUseCase(callable $fence): AuthorizeAdminAction
    {
        return new AuthorizeAdminAction(new PermissionResolver(), new AdminActionAuthorizer(), null, $fence);
    }

    // ---- action_fence seam (ADR admin-shell_action-fence-seam) -------------

    public function test_fence_veto_denies_even_an_administrator(): void
    {
        // The fence runs BEFORE the RBAC core, so its veto binds the administrator
        // bypass too — a role granted later cannot open a fenced screen.
        $decision = $this->fencedUseCase(static fn () => 'store_fence:global')->authorize(
            new FakeAdminUser(administrator: true),
            'gp247_admin/config',
            'mount',
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('store_fence:global', $decision->reason());
    }

    public function test_fence_null_leaves_the_decision_to_rbac(): void
    {
        // A fence that does not veto is transparent: RBAC decides exactly as before.
        $decision = $this->fencedUseCase(static fn () => null)->authorize(
            new FakeAdminUser(uris: ['gp247_admin/product']),
            'gp247_admin/product',
            'save',
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_throwing_fence_fails_closed(): void
    {
        $decision = $this->fencedUseCase(static function () {
            throw new \RuntimeException('classifier exploded');
        })->authorize(
            new FakeAdminUser(administrator: true),
            'gp247_admin/product',
            'mount',
        );

        $this->assertFalse($decision->isAllowed(), 'a fence that cannot answer must deny, never fall through');
        $this->assertSame('action_fence_error', $decision->reason());
    }

    public function test_lifecycle_mount_is_treated_as_read_for_view_all(): void
    {
        $decision = $this->useCase()->authorize(
            new FakeAdminUser(viewAll: true),
            'gp247_admin/product',
            'mount',
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_mutating_action_blocked_for_view_all(): void
    {
        $decision = $this->useCase()->authorize(
            new FakeAdminUser(viewAll: true),
            'gp247_admin/product',
            'delete',
        );

        $this->assertFalse($decision->isAllowed());
    }

    public function test_read_listing_action_allowed_for_view_all(): void
    {
        $decision = $this->useCase()->authorize(
            new FakeAdminUser(viewAll: true),
            'gp247_admin/product',
            'setSort',
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_mutating_action_allowed_with_matching_uri(): void
    {
        $decision = $this->useCase()->authorize(
            new FakeAdminUser(uris: ['gp247_admin/product']),
            'gp247_admin/product',
            'save',
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_unresolvable_screen_denied_for_regular_user(): void
    {
        $decision = $this->useCase()->authorize(
            new FakeAdminUser(uris: ['gp247_admin/product']),
            null,
            'delete',
        );

        $this->assertFalse($decision->isAllowed());
    }

    public function test_administrator_allowed_regardless(): void
    {
        $decision = $this->useCase()->authorize(
            new FakeAdminUser(administrator: true),
            null,
            'delete',
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function test_is_mutating_classifies_lifecycle_and_actions(): void
    {
        $useCase = $this->useCase();

        $this->assertFalse($useCase->isMutating('mount'));
        $this->assertFalse($useCase->isMutating('setSort'));
        $this->assertTrue($useCase->isMutating('delete'));
        $this->assertTrue($useCase->isMutating('save'));
    }

    public function test_custom_read_only_list_is_honored(): void
    {
        $useCase = new AuthorizeAdminAction(new PermissionResolver(), new AdminActionAuthorizer(), ['ping']);

        $this->assertFalse($useCase->isMutating('ping'));
        $this->assertTrue($useCase->isMutating('mount'));
    }
}
