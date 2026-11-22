<?php

namespace GP247\Core\Tests\Unit\ApiSync;

use GP247\Core\Api\Sync\ApiEventSource;
use GP247\Core\Api\Sync\EventType;
use PHPUnit\Framework\TestCase;

/**
 * Field allowlist and name rule of event types; source of a change from its request path.
 */
class EventTypeTest extends TestCase
{
    public function test_only_declared_fields_are_kept(): void
    {
        $this->assertSame(['status' => 2, 'qty' => 1], EventType::keep(['status', 'qty'], ['qty' => 1, 'email' => 'x@y.z', 'status' => 2]));
        $this->assertSame([], EventType::keep([], ['status' => 2]));
    }

    public function test_type_names_are_resource_dot_action(): void
    {
        foreach (['order.created', 'stock.changed', 'multivendor.payout_sent'] as $ok) {
            $this->assertTrue(EventType::isValidName($ok), $ok);
        }
        foreach (['', 'order', 'Order.Created', 'order..x', 'order.created ', str_repeat('a', 70) . '.x'] as $bad) {
            $this->assertFalse(EventType::isValidName($bad), $bad);
        }
    }

    public function test_the_source_follows_the_request_path(): void
    {
        $this->assertSame('api', ApiEventSource::fromPath('api/v2/core/order/1/status', 'gp247_admin'));
        $this->assertSame('api', ApiEventSource::fromPath('api/front/login', 'gp247_admin'));
        $this->assertSame('admin', ApiEventSource::fromPath('gp247_admin/order', 'gp247_admin'));
        $this->assertSame('web', ApiEventSource::fromPath('checkout', 'gp247_admin'));
        $this->assertSame('system', ApiEventSource::fromPath(null, 'gp247_admin'));
    }
}
