<?php

namespace GP247\Core\Backup\Application;

/**
 * Checks of the address an administrator gives for an external destination, before the server
 * connects to it: no cloud metadata service or link-local address (a stolen admin session must
 * not turn "Test the connection" into a probe of the host's own credentials), no user:password in
 * URLs, plain http only towards this machine or a private network (a local MinIO).
 *
 * @aidlc-unit backup-storage
 * @aidlc-story US-backup-storage-s3-compatible, US-backup-storage-sftp, US-backup-storage-credential-secret
 * @aidlc-adr backup-storage_optional-adapters
 */
final class RemoteAddressGuard
{
    /** @var list<string> */
    private const BLOCKED_NAMES = ['metadata.google.internal', 'metadata', 'metadata.azure.internal', 'instance-data', 'instance-data.ec2.internal'];
    /** @var list<string> */
    private const BLOCKED_IPS = ['100.100.100.200'];

    /**
     * @param string $url S3 endpoint (empty = the provider's default endpoint).
     * @return string|null Problem code (endpoint_invalid | endpoint_http | endpoint_blocked), null when acceptable.
     */
    public static function endpointProblem(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return 'endpoint_invalid';
        }
        $host = trim((string) $parts['host'], '[]');
        $problem = self::hostProblem($host);
        if ($problem !== null) {
            return $problem === 'host_invalid' ? 'endpoint_invalid' : 'endpoint_blocked';
        }
        if (strtolower((string) $parts['scheme']) === 'http' && !self::isLocal($host)) {
            return 'endpoint_http';
        }

        return null;
    }

    /**
     * @param string $host Host name or IP (SFTP server, S3 endpoint host).
     * @return string|null host_invalid | host_blocked, null when acceptable.
     */
    public static function hostProblem(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 253 || preg_match('/^[a-z0-9.:\-]+$/D', $host) !== 1) {
            return 'host_invalid';
        }
        if (in_array(rtrim($host, '.'), self::BLOCKED_NAMES, true)) {
            return 'host_blocked';
        }
        foreach (self::addresses($host) as $ip) {
            if (self::blockedIp($ip)) {
                return 'host_blocked';
            }
        }

        return null;
    }

    /**
     * @param string $host
     * @return bool Every address of the host is loopback or private (or the host is "localhost").
     */
    public static function isLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[] '));
        if ($host === 'localhost') {
            return true;
        }
        $ips = self::addresses($host);
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $host
     * @return list<string> IPs of the host (the host itself when it is an IP; empty when it does not resolve).
     */
    private static function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = @gethostbynamel($host);
        $ips = is_array($ips) ? $ips : [];
        // gethostbynamel() knows IPv4 only; a name may also carry an IPv6 address (link-local, fd00:ec2::254).
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_AAAA) : false;
        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * @param string $ip
     * @return bool Link-local (169.254/16, fe80::/10 — where cloud metadata lives) or a known metadata address.
     */
    private static function blockedIp(string $ip): bool
    {
        if (in_array($ip, self::BLOCKED_IPS, true)) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($ip, '169.254.');
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }
        // IPv4-mapped (::ffff:a.b.c.d): judge the IPv4 address.
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return self::blockedIp((string) inet_ntop(substr($packed, 12)));
        }

        return (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80) || inet_ntop($packed) === 'fd00:ec2::254';
    }
}
