<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Support\SettingsHubTabRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Configuration hub tab registry seam: registration is
 * idempotent by key and all() returns tabs ordered by (order, key).
 *
 * @aidlc-unit admin-shell-rbac
 */
class SettingsHubTabRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SettingsHubTabRegistry::reset();
    }

    protected function tearDown(): void
    {
        SettingsHubTabRegistry::reset();
        parent::tearDown();
    }

    public function test_registers_and_returns_a_tab(): void
    {
        SettingsHubTabRegistry::register('shop', 'lang.shop', 'ns::shop-form', 'gp247_admin/shop_config', 10);

        $tabs = SettingsHubTabRegistry::all();

        $this->assertCount(1, $tabs);
        $this->assertSame('shop', $tabs[0]['key']);
        $this->assertSame('lang.shop', $tabs[0]['label']);
        $this->assertSame('ns::shop-form', $tabs[0]['component']);
        $this->assertSame('gp247_admin/shop_config', $tabs[0]['authUri']);
        $this->assertSame(10, $tabs[0]['order']);
    }

    public function test_registration_is_idempotent_by_key(): void
    {
        SettingsHubTabRegistry::register('shop', 'lang.a', 'ns::a', 'gp247_admin/shop_config', 10);
        SettingsHubTabRegistry::register('shop', 'lang.b', 'ns::b', 'gp247_admin/shop_config', 10);

        $tabs = SettingsHubTabRegistry::all();

        $this->assertCount(1, $tabs, 'same key overwrites rather than duplicates');
        $this->assertSame('lang.b', $tabs[0]['label']);
    }

    public function test_all_is_ordered_by_order_then_key(): void
    {
        SettingsHubTabRegistry::register('zeta', 'l', 'c', 'u', 20);
        SettingsHubTabRegistry::register('alpha', 'l', 'c', 'u', 20);
        SettingsHubTabRegistry::register('first', 'l', 'c', 'u', 5);

        $keys = array_column(SettingsHubTabRegistry::all(), 'key');

        $this->assertSame(['first', 'alpha', 'zeta'], $keys);
    }
}
