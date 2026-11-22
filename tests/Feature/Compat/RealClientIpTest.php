<?php

namespace GP247\Core\Tests\Feature\Compat;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use GP247\Core\Tests\TestCase;

/**
 * Verifies request()->ip() (used by gp247_get_real_ip_client() and by
 * direct callers such as ShopCartController) resolves the real visitor IP
 * across the deployment presets documented in .env.example: unset (bare host
 * and the Docker default — trust nothing), an external edge's CIDR ranges
 * (Cloudflare/GCP/AWS LB, bare host or Docker), and the Docker bridge range for
 * a reverse proxy on the same host. Behind the bundled nginx, php-fpm receives
 * the visitor's own address as REMOTE_ADDR (FastCGI `$remote_addr`), so the
 * "REMOTE_ADDR" sentinel trusts the visitor and must not be used.
 * TRUSTED_PROXIES flows through config/trustedproxy.php — a
 * config-cache-safe fallback Illuminate\Http\Middleware\TrustProxies reads
 * natively (never env() at request time).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-DEP-trusted-proxy-explicit
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
class RealClientIpTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function resolveIp(string $remoteAddr, ?string $forwardedFor = null, array|string|null $trustedProxies = null): ?string
    {
        $server = ['REMOTE_ADDR' => $remoteAddr];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        if ($trustedProxies !== null) {
            TrustProxies::at($trustedProxies);
        }

        $request = Request::create('/', 'GET', [], [], [], $server);

        return (new TrustProxies())->handle($request, fn ($req) => $req)->ip();
    }

    /**
     * Run TrustProxies for a request that reached PHP through several proxies.
     *
     * @param string $remoteAddr     Direct peer seen by PHP.
     * @param string $forwardedFor   X-Forwarded-For chain.
     * @param string $trustedProxies TRUSTED_PROXIES value.
     * @return Request
     */
    private function throughProxies(string $remoteAddr, string $forwardedFor, string $trustedProxies): Request
    {
        TrustProxies::at($trustedProxies);
        $request = Request::create('http://shop.example/', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        return (new TrustProxies())->handle($request, fn ($req) => $req);
    }

    /**
     * Cloudflare -> nginx on the host -> Docker: listing every hop (Docker bridge range
     * plus the CDN ranges) gives the visitor IP and https URLs.
     */
    public function test_docker_behind_a_host_proxy_behind_a_cdn_resolves_the_visitor_and_https_when_every_hop_is_listed(): void
    {
        $request = $this->throughProxies('172.18.0.1', '203.0.113.7, 172.70.1.2', '172.16.0.0/12,173.245.48.0/20,172.64.0.0/13');

        $this->assertSame('203.0.113.7', $request->ip());
        $this->assertTrue($request->isSecure());
    }

    /**
     * Same chain with only the CDN ranges listed (incident 2026-11-12): the Docker
     * gateway is not trusted, every forwarded header is ignored, URLs become http://.
     */
    public function test_docker_behind_a_host_proxy_behind_a_cdn_with_only_cdn_ranges_falls_back_to_http(): void
    {
        $request = $this->throughProxies('172.18.0.1', '203.0.113.7, 172.70.1.2', '173.245.48.0/20,172.64.0.0/13');

        $this->assertSame('172.18.0.1', $request->ip());
        $this->assertFalse($request->isSecure());
    }

    /**
     * A request arriving from Cloudflare's edge network must resolve to the
     * visitor IP Cloudflare forwarded, not Cloudflare's own connecting IP — once the
     * Cloudflare ranges are listed. WHY explicit: the test used to pass only on proxy
     * state left over by an earlier test (it failed when run alone).
     */
    public function test_forwarded_ip_is_trusted_when_request_comes_from_cloudflare(): void
    {
        $this->assertSame(
            '203.0.113.7',
            $this->resolveIp(remoteAddr: '172.64.10.5', forwardedFor: '203.0.113.7', trustedProxies: '173.245.48.0/20,172.64.0.0/13')
        );
    }

    /**
     * Bare-host-behind-Cloudflare preset (CIDR list only, no REMOTE_ADDR —
     * see .env.example): a request that bypasses Cloudflare and hits the
     * origin directly must not be able to spoof its IP via a forged
     * X-Forwarded-For header. The same holds in Docker, whose presets no
     * longer include REMOTE_ADDR.
     */
    public function test_forwarded_header_is_ignored_when_request_bypasses_cloudflare(): void
    {
        $this->assertSame(
            '198.51.100.9',
            $this->resolveIp(
                remoteAddr: '198.51.100.9',
                forwardedFor: '203.0.113.7',
                trustedProxies: '172.64.0.0/13'
            )
        );
    }

    /**
     * gp247_get_real_ip_client() must be defined and simply delegate to
     * request()->ip() (no separate resolution logic to keep in sync).
     */
    public function test_gp247_get_real_ip_client_is_defined(): void
    {
        $this->assertTrue(function_exists('gp247_get_real_ip_client'));
    }

    /**
     * Docker default (no proxy in front): php-fpm sees the visitor as
     * REMOTE_ADDR and nothing is trusted, so a forged X-Forwarded-For is ignored.
     */
    public function test_docker_default_ignores_a_forged_forwarded_for(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame('203.0.113.7', $this->resolveIp(remoteAddr: '203.0.113.7', forwardedFor: '127.0.0.1'));
    }

    /**
     * Why the "REMOTE_ADDR" sentinel is no longer a preset: under FastCGI it is
     * the visitor, so trusting it lets the visitor pick their own IP. The
     * trusted_proxies check warns about this value.
     */
    public function test_the_remote_addr_sentinel_trusts_the_visitor_under_fastcgi(): void
    {
        $this->assertSame(
            '127.0.0.1',
            $this->resolveIp(remoteAddr: '203.0.113.7', forwardedFor: '127.0.0.1', trustedProxies: 'REMOTE_ADDR')
        );
    }

    /**
     * Docker behind Cloudflare: nginx passes Cloudflare's edge as REMOTE_ADDR,
     * and Cloudflare appends the visitor after whatever the visitor forged.
     */
    public function test_docker_behind_cloudflare_resolves_the_visitor_cloudflare_appended(): void
    {
        $this->assertSame(
            '203.0.113.7',
            $this->resolveIp(remoteAddr: '172.64.10.5', forwardedFor: '127.0.0.1, 203.0.113.7', trustedProxies: '172.64.0.0/13')
        );
    }

    /**
     * Docker behind nginx / Caddy / Traefik on the same host: the container sees
     * the docker gateway; trusting the bridge range reaches the visitor.
     */
    public function test_docker_behind_a_reverse_proxy_on_the_same_host_resolves_the_visitor(): void
    {
        $this->assertSame(
            '203.0.113.7',
            $this->resolveIp(remoteAddr: '172.18.0.1', forwardedFor: '203.0.113.7', trustedProxies: '172.16.0.0/12')
        );
    }
}
