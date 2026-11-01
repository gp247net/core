<?php

namespace GP247\Core\Api\Sync;

/**
 * The only way core sends a request to a URL an admin typed (webhooks): `https` only, no
 * credentials in the URL, port 443 or a high port, and every address the host resolves to
 * must be public — no loopback, private, link-local (cloud metadata), carrier-grade NAT,
 * reserved, IPv6 unique-local or IPv4-mapped address. Checked when the URL is saved and
 * again right before each send; the caller pins the connection to the checked addresses
 * so a DNS answer that changes in between cannot point it inside the network.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-register, US-api-sync-webhook-delivery
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
final class OutboundUrlGuard
{
    /** Ranges filter_var does not flag as private or reserved but that are not the public internet. */
    private const EXTRA_BLOCKED = ['100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '198.18.0.0/15', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96', '64:ff9b::/96'];

    /** @var callable|null Host => list of IPs; replaced in tests (no DNS). */
    private static $resolver = null;

    /**
     * Replace the host resolver (null restores DNS).
     *
     * @param callable|null $resolver fn (string $host): string[]
     * @return void
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * Check $url and return where to connect.
     *
     * @param string $url
     * @return array{host: string, port: int, ips: string[]}
     * @throws OutboundUrlRejected With the reason code when the URL may not be called.
     */
    public static function check(string $url): array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new OutboundUrlRejected('not_https');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundUrlRejected('credentials');
        }
        $port = (int) ($parts['port'] ?? 443);
        if ($port !== 443 && $port < 1024) {
            throw new OutboundUrlRejected('port');
        }
        $host = strtolower(trim((string) $parts['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($ips === []) {
            throw new OutboundUrlRejected('unresolvable');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new OutboundUrlRejected('blocked_address');
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => array_values($ips)];
    }

    /**
     * Whether $ip is an address of the public internet.
     *
     * @param string $ip
     * @return bool
     */
    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        foreach (self::EXTRA_BLOCKED as $cidr) {
            if (self::inRange($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Addresses (A and AAAA) of $host.
     *
     * @param string $host
     * @return string[]
     */
    private static function resolve(string $host): array
    {
        if (self::$resolver !== null) {
            return array_values(array_filter((array) (self::$resolver)($host), 'is_string'));
        }
        $ips = gethostbynamel($host) ?: [];
        foreach ((@dns_get_record($host, DNS_AAAA) ?: []) as $record) {
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Whether $ip falls in $cidr (same address family only).
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
