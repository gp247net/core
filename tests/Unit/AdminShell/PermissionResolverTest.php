<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Application\PermissionResolver;
use GP247\Core\AdminShell\Domain\PermissionKey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for mapping a Livewire component identifier to a permission key.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-002
 * @aidlc-adr ADR-001
 */
class PermissionResolverTest extends TestCase
{
    public function test_explicit_permission_takes_precedence(): void
    {
        $resolver = new PermissionResolver();

        $key = $resolver->resolve('gp247-core::user-list', 'admin_special');

        $this->assertInstanceOf(PermissionKey::class, $key);
        $this->assertSame('admin_special', $key->value());
    }

    public function test_explicit_map_resolves_by_component_identifier(): void
    {
        $resolver = new PermissionResolver(map: ['gp247-core::dashboard' => 'admin_home']);

        $key = $resolver->resolve('gp247-core::dashboard');

        $this->assertSame('admin_home', $key?->value());
    }

    public function test_convention_strips_action_suffix(): void
    {
        $resolver = new PermissionResolver();

        $this->assertSame('admin_user', $resolver->resolve('gp247-core::user-list')?->value());
        $this->assertSame('admin_product', $resolver->resolve('gp247-shop::product-edit')?->value());
    }

    public function test_convention_handles_compound_resource(): void
    {
        $resolver = new PermissionResolver();

        $this->assertSame('admin_custom_field', $resolver->resolve('gp247-core::custom-field-list')?->value());
    }

    public function test_convention_without_namespace(): void
    {
        $resolver = new PermissionResolver();

        $this->assertSame('admin_order', $resolver->resolve('order-show')?->value());
    }

    public function test_unresolvable_component_returns_null(): void
    {
        $resolver = new PermissionResolver();

        $this->assertNull($resolver->resolve('gp247-core::'));
        $this->assertNull($resolver->resolve(''));
    }

    public function test_blank_explicit_permission_is_ignored_and_falls_back(): void
    {
        $resolver = new PermissionResolver();

        // WHY: a blank declared permission must not produce an empty key; fall
        // back to convention so resolution stays deterministic.
        $this->assertSame('admin_user', $resolver->resolve('gp247-core::user-list', '   ')?->value());
    }
}
