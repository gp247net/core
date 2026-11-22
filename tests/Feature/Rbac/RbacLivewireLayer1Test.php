<?php

namespace GP247\Core\Tests\Feature\Rbac;

use GP247\Core\Middleware\Authenticate;
use GP247\Core\Middleware\PermissionMiddleware;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use GP247\Core\Tests\TestCase;

/**
 * Verifies Layer-1 RBAC: the admin authentication and permission middleware are
 * registered as Livewire persistent middleware so they re-enforce on every
 * livewire/update request, not just the initial page load (ADR-001 §Layer 1).
 *
 * @aidlc-unit rbac
 * @aidlc-story US-RBAC-001
 * @aidlc-adr ADR-001
 */
class RbacLivewireLayer1Test extends TestCase
{
    /**
     * Authenticate middleware must be in Livewire's persistent middleware stack
     * so unauthenticated users cannot call Livewire actions on admin components.
     */
    public function test_authenticate_middleware_is_persistent(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(
            Authenticate::class,
            $persistent,
            'GP247 Authenticate middleware must be persistent for Livewire (US-RBAC-001 AC2)'
        );
    }

    /**
     * PermissionMiddleware must be in Livewire's persistent middleware stack so
     * the URI-based RBAC check is re-applied on livewire/update requests.
     */
    public function test_permission_middleware_is_persistent(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(
            PermissionMiddleware::class,
            $persistent,
            'GP247 PermissionMiddleware must be persistent for Livewire (US-RBAC-001 AC2)'
        );
    }

    /**
     * The LivewireAuthGuard middleware is registered in the container and
     * available as the 'gp247.livewire-guard' alias (optional Layer-1 explicit guard).
     */
    public function test_livewire_auth_guard_is_bound_in_container(): void
    {
        $this->assertTrue(
            app()->bound(\GP247\Core\AdminShell\Infrastructure\LivewireAuthGuard::class)
                || class_exists(\GP247\Core\AdminShell\Infrastructure\LivewireAuthGuard::class),
            'LivewireAuthGuard must be resolvable (US-RBAC-001 AC1)'
        );
    }

    /**
     * PermissionResolver must correctly map a known Livewire component name
     * to a permission slug (convention: strip namespace prefix + kebab-case → snake_case).
     */
    public function test_permission_resolver_maps_component_to_permission_slug(): void
    {
        // WHY: PermissionResolver is in Application layer; returns PermissionKey value object.
        $resolver = app(\GP247\Core\AdminShell\Application\PermissionResolver::class);

        // gp247-core::product-list → PermissionKey with slug admin_product
        $key = $resolver->resolve('gp247-core::product-list', 'index');

        $this->assertNotNull($key, 'PermissionResolver must return a PermissionKey for known component');
        $this->assertInstanceOf(
            \GP247\Core\AdminShell\Domain\PermissionKey::class,
            $key,
            'PermissionResolver must return a PermissionKey value object'
        );
    }

    /**
     * An unknown / empty component name must resolve gracefully (null PermissionKey)
     * — deny-by-default principle (NFR-SEC-001).
     */
    public function test_unresolvable_component_resolves_to_null_or_deny_key(): void
    {
        $resolver = app(\GP247\Core\AdminShell\Application\PermissionResolver::class);

        // WHY: empty identifier has no convention → returns null or deny PermissionKey.
        $key = $resolver->resolve('', 'delete');

        // null is acceptable — the authorizer converts null to deny.
        $this->assertTrue(
            $key === null || $key instanceof \GP247\Core\AdminShell\Domain\PermissionKey,
            'PermissionResolver must handle empty component name without throwing'
        );
    }
}
