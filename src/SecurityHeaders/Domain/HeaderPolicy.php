<?php

namespace GP247\Core\SecurityHeaders\Domain;

/**
 * The set of security headers the site sends: each header on or off, HSTS (optional), and
 * the browser features extensions asked to open. Values are fixed here — the owner only
 * switches headers on or off, never types a header value.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-defaults, US-security-headers-hsts-opt-in, US-security-headers-settings
 * @aidlc-adr security-headers_response-middleware
 */
final class HeaderPolicy
{
    public const FRAME = 'X-Frame-Options';
    public const NOSNIFF = 'X-Content-Type-Options';
    public const REFERRER = 'Referrer-Policy';
    public const PERMISSIONS = 'Permissions-Policy';
    public const HSTS = 'Strict-Transport-Security';

    /** Browser features closed by default; an extension may open one for its own pages. */
    public const FEATURES = ['camera', 'microphone', 'geolocation'];

    /**
     * @param bool              $frame              Send X-Frame-Options: SAMEORIGIN.
     * @param bool              $nosniff            Send X-Content-Type-Options: nosniff.
     * @param bool              $referrer           Send Referrer-Policy.
     * @param bool              $permissions        Send Permissions-Policy.
     * @param HstsSetting|null  $hsts               HSTS parameters, null = off.
     * @param array<int, mixed> $grantedPermissions Features extensions asked to open (unknown names are ignored).
     */
    public function __construct(
        public readonly bool $frame = true,
        public readonly bool $nosniff = true,
        public readonly bool $referrer = true,
        public readonly bool $permissions = true,
        public readonly ?HstsSetting $hsts = null,
        public readonly array $grantedPermissions = [],
    ) {
    }

    /**
     * Policy of a fresh install: the four headers on, HSTS off.
     *
     * @return self
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Headers to add to a response of this kind (logical design §5.1).
     *
     * @param ResponseKind $kind
     * @param bool         $secure Whether the request came over HTTPS (trusted proxies included).
     * @return array<string, string> Header name => value.
     */
    public function headersFor(ResponseKind $kind, bool $secure): array
    {
        $headers = [];
        if ($this->nosniff) {
            $headers[self::NOSNIFF] = 'nosniff';
        }
        if ($kind === ResponseKind::Html && $this->frame) {
            $headers[self::FRAME] = 'SAMEORIGIN';
        }
        if ($kind !== ResponseKind::File && $this->referrer) {
            $headers[self::REFERRER] = 'strict-origin-when-cross-origin';
        }
        if ($kind === ResponseKind::Html && $this->permissions) {
            $headers[self::PERMISSIONS] = $this->permissionsValue();
        }
        // WHY secure only: browsers ignore HSTS over HTTP, and sending it there tells
        // nothing true about the site; the trusted-proxy setup decides what "secure" is.
        if ($this->hsts !== null && $secure) {
            $headers[self::HSTS] = $this->hsts->headerValue();
        }

        return $headers;
    }

    /**
     * Permissions-Policy value: every known feature closed unless an extension opened it.
     *
     * @return string
     */
    private function permissionsValue(): string
    {
        $parts = [];
        foreach (self::FEATURES as $feature) {
            $open = in_array($feature, $this->grantedPermissions, true);
            $parts[] = $feature . '=' . ($open ? '(self)' : '()');
        }

        return implode(', ', $parts);
    }
}
