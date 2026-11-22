<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Domain\PermissionKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PermissionKey value object.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-001
 */
class PermissionKeyTest extends TestCase
{
    public function test_it_exposes_its_trimmed_value(): void
    {
        $key = new PermissionKey('  admin_product  ');

        $this->assertSame('admin_product', $key->value());
        $this->assertSame('admin_product', (string) $key);
    }

    public function test_it_rejects_an_empty_value(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PermissionKey('   ');
    }

    public function test_it_compares_by_value(): void
    {
        $a = new PermissionKey('admin_product');
        $b = new PermissionKey('admin_product');
        $c = new PermissionKey('admin_order');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
