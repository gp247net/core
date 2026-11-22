<?php

namespace GP247\Core\Tests\Unit\ApiSync;

use GP247\Core\Api\Sync\OutboundUrlGuard;
use GP247\Core\Api\Sync\OutboundUrlRejected;
use PHPUnit\Framework\TestCase;

/**
 * Which addresses a webhook may be sent to: public internet only, https, no credentials.
 */
class OutboundUrlGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrlGuard::resolveUsing(null);
        parent::tearDown();
    }

    public function test_only_public_internet_addresses_are_allowed(): void
    {
        foreach (['93.184.216.34', '8.8.8.8', '2606:2800:220:1:248:1893:25c8:1946'] as $public) {
            $this->assertTrue(OutboundUrlGuard::isPublicIp($public), $public);
        }
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '198.18.0.1',
            '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1', 'not-an-ip'] as $blocked) {
            $this->assertFalse(OutboundUrlGuard::isPublicIp($blocked), $blocked);
        }
    }

    public function test_a_url_must_be_https_without_credentials_on_a_safe_port_and_resolve_to_public_addresses(): void
    {
        OutboundUrlGuard::resolveUsing(fn (string $host) => ['erp.example.com' => ['93.184.216.34'], 'inside.example.com' => ['93.184.216.34', '10.0.0.5']][$host] ?? []);

        $this->assertSame(['host' => 'erp.example.com', 'port' => 443, 'ips' => ['93.184.216.34']], OutboundUrlGuard::check('https://erp.example.com/hook'));
        $this->assertSame(8443, OutboundUrlGuard::check('https://erp.example.com:8443/hook')['port']);
        foreach ([
            'http://erp.example.com/hook' => 'not_https', 'ftp://erp.example.com' => 'not_https', 'https:///x' => 'not_https',
            'https://user:pw@erp.example.com/hook' => 'credentials', 'https://erp.example.com:22/hook' => 'port',
            'https://nowhere.example.com/hook' => 'unresolvable', 'https://inside.example.com/hook' => 'blocked_address',
            'https://127.0.0.1/hook' => 'blocked_address', 'https://[::1]/hook' => 'blocked_address',
        ] as $url => $reason) {
            try {
                OutboundUrlGuard::check($url);
                $this->fail("{$url} was accepted");
            } catch (OutboundUrlRejected $e) {
                $this->assertSame($reason, $e->getMessage(), $url);
            }
        }
    }
}
