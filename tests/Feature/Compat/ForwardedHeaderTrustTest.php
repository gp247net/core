<?php

namespace GP247\Core\Tests\Feature\Compat;

use GP247\Core\CoreServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use GP247\Core\Tests\TestCase;

/**
 * The host and path prefix of every generated URL come from the real Host header,
 * never from X-Forwarded-Host / X-Forwarded-Prefix — even when the request arrives
 * from a proxy declared in TRUSTED_PROXIES. Scheme, port and client IP are still
 * taken from a declared proxy (NFR-SEC-forwarded-header-trust).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-forwarded-host-ignored
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
class ForwardedHeaderTrustTest extends TestCase
{
    private const PROXY = '10.0.0.5';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['trustedproxy.proxies' => '10.0.0.0/8']);
        Route::get('/_forwarded-probe', fn (Request $request) => response()->json([
            'root' => url('/'),
            'ip' => $request->ip(),
            'host_trusted' => (Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_HOST) !== 0,
        ]));
    }

    /**
     * WHY: TrustProxies keeps its header set in a static; leave the default the
     * core provider sets on the next boot, and drop Symfony's static proxy list.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        Request::setTrustedProxies([], -1);

        parent::tearDown();
    }

    /**
     * @param string $remoteAddr
     * @param array<string, string> $headers
     * @return array{root: string, ip: string, host_trusted: bool}
     */
    private function probe(string $remoteAddr, array $headers): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
            ->withHeaders($headers)
            ->get('http://shop.example/_forwarded-probe')
            ->assertOk()
            ->json();
    }

    public function test_a_forwarded_host_and_prefix_from_a_declared_proxy_do_not_change_generated_urls(): void
    {
        $res = $this->probe(self::PROXY, [
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Prefix' => '/x',
        ]);

        $this->assertSame('http://shop.example', $res['root']);
        $this->assertFalse($res['host_trusted'], 'X-Forwarded-Host must not be in the trusted header set');
    }

    public function test_scheme_and_client_ip_from_a_declared_proxy_are_still_used(): void
    {
        $res = $this->probe(self::PROXY, [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.7',
            'X-Forwarded-Host' => 'evil.example',
        ]);

        $this->assertSame('https://shop.example', $res['root']);
        $this->assertSame('203.0.113.7', $res['ip']);
    }

    public function test_a_header_set_chosen_by_the_site_keeps_its_choice_without_host_or_prefix(): void
    {
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);

        $provider = $this->app->getProvider(CoreServiceProvider::class);
        (new \ReflectionMethod($provider, 'restrictTrustedProxyHeaders'))->invoke($provider);

        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
            (new \ReflectionProperty(TrustProxies::class, 'alwaysTrustHeaders'))->getValue()
        );
    }

    public function test_forwarded_headers_from_an_undeclared_peer_are_ignored(): void
    {
        $res = $this->probe('198.51.100.9', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '127.0.0.1',
            'X-Forwarded-Host' => 'evil.example',
        ]);

        $this->assertSame('http://shop.example', $res['root']);
        $this->assertSame('198.51.100.9', $res['ip']);
    }
}
