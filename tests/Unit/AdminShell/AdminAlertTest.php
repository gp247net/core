<?php

namespace GP247\Core\Tests\Unit\AdminShell;

use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use PHPUnit\Framework\TestCase;

/**
 * Value object behind every dashboard alert: levels, ordering weight and the
 * array round-trip used by the cache.
 */
class AdminAlertTest extends TestCase
{
    public function test_factories_set_the_level_and_only_ok_counts_as_passed(): void
    {
        $this->assertSame(AdminAlert::CRITICAL, AdminAlert::critical('k', 'm')->level);
        $this->assertSame(AdminAlert::WARNING, AdminAlert::warning('k', 'm')->level);
        $this->assertSame(AdminAlert::INFO, AdminAlert::info('k', 'm')->level);
        $this->assertTrue(AdminAlert::ok('k', 'm')->passed());
        $this->assertFalse(AdminAlert::info('k', 'm')->passed());
    }

    public function test_weight_orders_critical_before_warning_before_info_before_ok(): void
    {
        $this->assertSame([0, 1, 2, 3], [
            AdminAlert::critical('k', 'm')->weight(),
            AdminAlert::warning('k', 'm')->weight(),
            AdminAlert::info('k', 'm')->weight(),
            AdminAlert::ok('k', 'm')->weight(),
        ]);
    }

    public function test_an_unknown_level_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AdminAlert('k', 'urgent', 'm');
    }

    public function test_array_round_trip_keeps_every_field(): void
    {
        $alert = AdminAlert::warning('k', 'message', detail: 'detail', fixUrl: '/fix', fixLabel: 'Fix', docUrl: '/doc', items: ['a', 'b']);

        $this->assertEquals($alert, AdminAlert::fromArray($alert->toArray()));
    }

    public function test_from_array_rejects_a_malformed_payload(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AdminAlert::fromArray(['key' => 'k']);
    }
}
