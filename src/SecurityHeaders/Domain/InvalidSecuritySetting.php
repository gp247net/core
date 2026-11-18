<?php

namespace GP247\Core\SecurityHeaders\Domain;

use DomainException;

/**
 * A security-header setting that breaks one of its rules (an HSTS max-age outside the
 * steps, preload without subdomains or under one year, HSTS on a site that is not HTTPS).
 * The message is a language code, so the settings screen can show it as is.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-hsts-opt-in, US-security-headers-settings
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
final class InvalidSecuritySetting extends DomainException
{
    /**
     * Build the error from the language code that explains it.
     *
     * @param string $languageCode Code rendered by gp247_language_render().
     * @return self
     */
    public static function because(string $languageCode): self
    {
        return new self($languageCode);
    }
}
