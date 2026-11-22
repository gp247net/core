<?php

namespace GP247\Core\Tests\Unit\Compat;

use GP247\Core\Mail\SmtpTransport;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for the SMTP transport mapping (no Laravel boot / no DB) so the
 * scheme/port logic — which drives whether SSL/465 is actually encrypted — is
 * verifiable in isolation (MC-mail-delivery, RISK-TECH-mail-scheme-mismatch).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-delivery-hardening
 * @aidlc-adr compat-foundation_mail-delivery-hardening
 */
class SmtpTransportTest extends TestCase
{
    /**
     * 'ssl' must map to the implicit-TLS scheme 'smtps'; everything else to 'smtp'.
     */
    public function test_scheme_maps_ssl_to_smtps(): void
    {
        $this->assertSame('smtps', SmtpTransport::scheme('ssl'));
    }

    /**
     * 'tls' uses STARTTLS over the plain 'smtp' scheme.
     */
    public function test_scheme_maps_tls_to_smtp(): void
    {
        $this->assertSame('smtp', SmtpTransport::scheme('tls'));
    }

    /**
     * Empty / null / unknown security values fall back to 'smtp' (never crash).
     */
    public function test_scheme_falls_back_to_smtp_for_empty_null_and_unknown(): void
    {
        $this->assertSame('smtp', SmtpTransport::scheme(''));
        $this->assertSame('smtp', SmtpTransport::scheme(null));
        $this->assertSame('smtp', SmtpTransport::scheme('whatever'));
    }

    /**
     * Case sensitivity: only exact 'ssl' is implicit TLS (config stores lowercase).
     */
    public function test_scheme_is_case_sensitive(): void
    {
        $this->assertSame('smtp', SmtpTransport::scheme('SSL'));
    }

    /**
     * Default port is 465 for smtps and 587 for smtp — used when admin leaves it blank.
     */
    public function test_default_port_matches_scheme(): void
    {
        $this->assertSame(465, SmtpTransport::defaultPort('smtps'));
        $this->assertSame(587, SmtpTransport::defaultPort('smtp'));
    }
}
