<?php

namespace GP247\Core\SecurityHeaders\Domain;

/**
 * Strict-Transport-Security parameters. max-age is picked from fixed steps so an owner
 * climbs slowly (a long max-age stays in visitors' browsers and cannot be taken back),
 * and preload is only allowed where the preload list accepts it.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-hsts-opt-in
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
final class HstsSetting
{
    /** 5 minutes · 1 day · 1 week · 1 month · 6 months · 1 year · 2 years (seconds). */
    public const STEPS = [300, 86400, 604800, 2592000, 15552000, 31536000, 63072000];

    /** Shortest max-age the browser preload list accepts. */
    public const PRELOAD_MIN = 31536000;

    /**
     * @param int  $maxAge            One of STEPS.
     * @param bool $includeSubDomains Apply to every subdomain too.
     * @param bool $preload           Ask to be listed in browsers' built-in HSTS list.
     * @throws InvalidSecuritySetting When a rule is broken.
     */
    public function __construct(
        public readonly int $maxAge,
        public readonly bool $includeSubDomains = false,
        public readonly bool $preload = false,
    ) {
        if (!in_array($maxAge, self::STEPS, true)) {
            throw InvalidSecuritySetting::because('admin.security_headers.error_hsts_step');
        }
        if ($preload && (!$includeSubDomains || $maxAge < self::PRELOAD_MIN)) {
            throw InvalidSecuritySetting::because('admin.security_headers.error_hsts_preload');
        }
    }

    /**
     * Value of the Strict-Transport-Security header.
     *
     * @return string
     */
    public function headerValue(): string
    {
        $value = 'max-age=' . $this->maxAge;
        if ($this->includeSubDomains) {
            $value .= '; includeSubDomains';
        }
        if ($this->preload) {
            $value .= '; preload';
        }

        return $value;
    }
}
