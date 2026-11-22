<?php

namespace GP247\Core\Tests\Feature\SecurityHeaders;

use GP247\Core\SecurityHeaders\Application\SecurityHeaderSettings;
use GP247\Core\SecurityHeaders\Domain\InvalidSecuritySetting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Tests\Feature\SecurityHeaders\Support\ConfiguresSecurityHeaders;
use GP247\Core\Tests\TestCase;

/**
 * HSTS is opt-in, only over HTTPS, with safe steps; the emergency switch in the
 * environment silences every header the application sends.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-hsts-opt-in, US-security-headers-emergency-off
 * @aidlc-adr security-headers_config-hsts-and-kill-switch
 */
#[Group('security-headers')]
class SecurityHeadersHstsAndKillSwitchTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresSecurityHeaders;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetConfigMemo();
        $this->registerProbeRoutes();
    }

    public function test_hsts_is_off_on_a_site_whose_owner_never_turned_it_on(): void
    {
        $this->get('https://localhost/__sh-test/page')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_turned_on_is_sent_over_https_with_the_chosen_max_age(): void
    {
        $this->setSecuritySettings(['security_hsts_enabled' => 1, 'security_hsts_max_age' => 86400]);

        $this->get('https://localhost/__sh-test/page')->assertHeader('Strict-Transport-Security', 'max-age=86400');
    }

    public function test_hsts_is_never_sent_over_plain_http(): void
    {
        $this->setSecuritySettings(['security_hsts_enabled' => 1, 'security_hsts_max_age' => 86400]);

        $this->get('http://localhost/__sh-test/page')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_cannot_be_turned_on_when_the_site_address_is_not_https(): void
    {
        $this->expectException(InvalidSecuritySetting::class);

        SecurityHeaderSettings::save(['security_hsts_enabled' => true, 'security_hsts_max_age' => 300], 'http://shop.example');
    }

    public function test_preload_is_refused_without_subdomains_or_under_one_year(): void
    {
        foreach ([
            ['security_hsts_subdomains' => false, 'security_hsts_max_age' => 31536000],
            ['security_hsts_subdomains' => true, 'security_hsts_max_age' => 2592000],
        ] as $case) {
            try {
                SecurityHeaderSettings::save(['security_hsts_enabled' => true, 'security_hsts_preload' => true] + $case, 'https://shop.example');
                $this->fail('preload must be refused');
            } catch (InvalidSecuritySetting $e) {
                $this->assertSame('admin.security_headers.error_hsts_preload', $e->getMessage());
            }
        }
    }

    public function test_a_broken_hsts_value_written_by_hand_in_the_database_turns_hsts_off_instead_of_breaking_pages(): void
    {
        $this->setSecuritySettings(['security_hsts_enabled' => 1, 'security_hsts_max_age' => 999]);

        $this->get('https://localhost/__sh-test/page')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_emergency_switch_silences_every_header_the_application_sends(): void
    {
        $this->setSecuritySettings(['security_hsts_enabled' => 1, 'security_hsts_max_age' => 86400]);
        config(['gp247-config.security_headers.enabled' => false]);

        $response = $this->get('https://localhost/__sh-test/page');

        foreach (['X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'Strict-Transport-Security'] as $name) {
            $response->assertHeaderMissing($name);
        }
    }

    public function test_the_emergency_switch_is_read_from_the_environment_variable(): void
    {
        $config = require base_path('vendor/gp247/core/src/Config/gp247-config.php');

        $this->assertArrayHasKey('security_headers', $config);
        $this->assertArrayHasKey('enabled', $config['security_headers']);
        $this->assertStringContainsString(
            "env('GP247_SECURITY_HEADERS'",
            (string) file_get_contents(base_path('vendor/gp247/core/src/Config/gp247-config.php'))
        );
    }
}
