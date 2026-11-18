<?php

namespace GP247\Core\SecurityHeaders\Application;

use GP247\Core\SecurityHeaders\Domain\HeaderPolicy;

/**
 * The policy in force for this request: null when the emergency switch in the environment
 * is off (it wins over every stored setting), otherwise the stored settings plus the
 * browser features extensions registered in `gp247-config.security_headers.permissions_allow`.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-emergency-off, US-security-headers-defaults
 * @aidlc-adr security-headers_config-hsts-and-kill-switch, security-headers_response-middleware
 */
final class HeaderPolicyResolver
{
    /**
     * Whether the application sends security headers at all (GP247_SECURITY_HEADERS).
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return (bool) config('gp247-config.security_headers.enabled', true);
    }

    /**
     * Policy for the current request, or null when the emergency switch is off.
     *
     * @return HeaderPolicy|null
     */
    public function current(): ?HeaderPolicy
    {
        if (!$this->enabled()) {
            return null;
        }
        $granted = config('gp247-config.security_headers.permissions_allow', []);

        return SecurityHeaderSettings::policy(is_array($granted) ? array_values($granted) : []);
    }
}
