<?php

namespace GP247\Core\Mail;

/**
 * Pure mapping between the admin `smtp_security` config and the Symfony Mailer
 * transport (scheme + default port). Kept free of Laravel/DB dependencies so it
 * is unit-testable in isolation.
 *
 * WHY this exists: modern config/mail.php (Laravel 11+/Symfony Mailer) drives the
 * SMTP transport via `scheme`, not `encryption`. Laravel's `encryption` fallback
 * only maps 'tls' (not 'ssl'), so an SSL/465 setup would silently downgrade to an
 * unencrypted 'smtp' connection. Mapping `ssl` → `smtps` here restores implicit TLS.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-delivery-hardening
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
final class SmtpTransport
{
    /**
     * Map the admin `smtp_security` value to a Symfony Mailer scheme.
     *
     * @param string|null $security Admin config value: 'ssl', 'tls', '' or null.
     * @return string 'smtps' for implicit TLS (ssl), otherwise 'smtp' (STARTTLS/none).
     */
    public static function scheme(?string $security): string
    {
        // WHY exact 'ssl' only: the config field stores lowercase tokens; any other
        // value (tls, empty, null, unexpected) is served over plain 'smtp'.
        return ($security === 'ssl') ? 'smtps' : 'smtp';
    }

    /**
     * Default SMTP port to use when the admin leaves `smtp_port` blank.
     *
     * @param string $scheme Scheme from {@see self::scheme()}.
     * @return int 465 for 'smtps' (implicit TLS), otherwise 587 (submission/STARTTLS).
     */
    public static function defaultPort(string $scheme): int
    {
        return ($scheme === 'smtps') ? 465 : 587;
    }
}
