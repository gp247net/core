<?php

namespace GP247\Core\Tests\Feature\SecurityHeaders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Tests\Feature\SecurityHeaders\Support\ConfiguresSecurityHeaders;
use GP247\Core\Tests\TestCase;

/**
 * A site with no header setting touched sends the four security headers on its pages,
 * fewer on JSON and files, and never overwrites a header the page already chose.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-defaults, US-security-headers-respect-server
 * @aidlc-adr security-headers_response-middleware
 */
#[Group('security-headers')]
class SecurityHeadersDefaultsTest extends TestCase
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

    public function test_the_admin_sign_in_page_carries_the_four_security_headers(): void
    {
        $response = $this->get(gp247_route_admin('admin.login'));

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_a_storefront_page_carries_the_four_security_headers(): void
    {
        $response = $this->get('/__sh-test/page');

        $response->assertOk();
        foreach (['X-Frame-Options', 'X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy'] as $name) {
            $response->assertHeader($name);
        }
    }

    public function test_a_json_answer_of_the_api_only_gets_the_headers_that_mean_something_for_json(): void
    {
        $response = $this->getJson('/__sh-test/api');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeaderMissing('Permissions-Policy');
    }

    public function test_a_downloaded_file_only_gets_nosniff(): void
    {
        $response = $this->get('/__sh-test/download');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeaderMissing('X-Frame-Options');
        $response->assertHeaderMissing('Referrer-Policy');
    }

    public function test_a_header_the_page_already_set_is_kept_and_not_sent_twice(): void
    {
        $response = $this->get('/__sh-test/own-frame');

        $this->assertSame(['ALLOWALL'], $response->headers->all('x-frame-options'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_route_outside_the_web_and_api_groups_is_left_alone(): void
    {
        $this->get('/__sh-test/outside')->assertHeaderMissing('X-Frame-Options');
    }

    public function test_a_browser_feature_an_extension_asked_for_is_opened_for_the_site(): void
    {
        config(['gp247-config.security_headers.permissions_allow' => ['geolocation']]);

        $this->get('/__sh-test/page')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');
    }

    public function test_a_header_the_owner_switched_off_is_no_longer_sent(): void
    {
        $this->setSecuritySettings(['security_header_frame' => 0]);

        $this->get('/__sh-test/page')
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
