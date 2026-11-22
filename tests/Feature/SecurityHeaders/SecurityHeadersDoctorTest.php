<?php

namespace GP247\Core\Tests\Feature\SecurityHeaders;

use GP247\Core\AdminShell\Infrastructure\AdminAlert;
use GP247\Core\SecurityHeaders\Application\SecurityHeaderSettings;
use GP247\Core\SecurityHeaders\Infrastructure\SecurityHeadersCheck;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Core\Tests\Feature\SecurityHeaders\Support\ConfiguresSecurityHeaders;
use GP247\Core\Tests\TestCase;

/**
 * The system alerts and `gp247:doctor` say whether the site really sends its security
 * headers; reading the live answer of the site only happens when asked with a flag.
 *
 * @aidlc-unit security-headers
 * @aidlc-story US-security-headers-doctor-checks
 * @aidlc-adr security-headers_doctor-live-probe
 */
#[Group('security-headers')]
class SecurityHeadersDoctorTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguresSecurityHeaders;

    private const HEADERS_ON = [
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->forgetConfigMemo();
        config(['app.url' => 'https://shop.example']);
    }

    /**
     * Run gp247:doctor --json and return the row of one check.
     *
     * @param string               $name
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function doctorRow(string $name, array $args = []): ?array
    {
        $buffer = new BufferedOutput();
        Artisan::call('gp247:doctor', array_merge($args, ['--json' => true]), $buffer);
        $lines = array_filter(array_map('trim', explode("\n", $buffer->fetch())), 'strlen');
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['data']['checks'])) {
                foreach ($decoded['data']['checks'] as $row) {
                    if (($row['name'] ?? '') === $name) {
                        return $row;
                    }
                }
                return null;
            }
        }

        return null;
    }

    public function test_a_site_with_every_header_on_is_reported_fine(): void
    {
        $this->assertSame(AdminAlert::OK, app(SecurityHeadersCheck::class)->evaluate()->level);
    }

    public function test_a_header_the_owner_switched_off_is_listed_as_information(): void
    {
        $this->setSecuritySettings([SecurityHeaderSettings::FRAME => 0, SecurityHeaderSettings::PERMISSIONS => 0]);

        $alert = app(SecurityHeadersCheck::class)->evaluate();

        $this->assertSame(AdminAlert::INFO, $alert->level);
        $this->assertSame(['X-Frame-Options', 'Permissions-Policy'], $alert->items);
    }

    public function test_the_emergency_switch_is_reported_as_a_warning(): void
    {
        config(['gp247-config.security_headers.enabled' => false]);

        $this->assertSame(AdminAlert::WARNING, app(SecurityHeadersCheck::class)->evaluate()->level);
    }

    public function test_hsts_on_a_site_whose_address_is_not_https_is_critical(): void
    {
        $this->setSecuritySettings([SecurityHeaderSettings::HSTS_ENABLED => 1]);
        config(['app.url' => 'http://shop.example']);

        $this->assertSame(AdminAlert::CRITICAL, app(SecurityHeadersCheck::class)->evaluate()->level);
    }

    public function test_doctor_lists_the_check_and_sends_no_request_without_the_flag(): void
    {
        Http::fake();

        $this->assertNotNull($this->doctorRow('security_headers'));
        $this->assertNull($this->doctorRow('security_headers_live'));
        // WHY not assertNothingSent(): other doctor checks call their own targets (the backup
        // vault check requests one file path of the site); the header probe requests the
        // site's home page, and that must never happen without the flag.
        Http::assertNotSent(fn ($request) => rtrim($request->url(), '/') === 'https://shop.example');
    }

    public function test_reading_the_live_site_passes_when_every_header_arrives_once(): void
    {
        Http::fake(['https://shop.example*' => Http::response('<p>ok</p>', 200, self::HEADERS_ON)]);

        $row = $this->doctorRow('security_headers_live', ['--probe-headers' => true]);

        $this->assertSame('pass', $row['status']);
    }

    public function test_reading_the_live_site_warns_about_a_missing_header(): void
    {
        $headers = self::HEADERS_ON;
        unset($headers['Referrer-Policy']);
        Http::fake(['https://shop.example*' => Http::response('<p>ok</p>', 200, $headers)]);

        $row = $this->doctorRow('security_headers_live', ['--probe-headers' => true]);

        $this->assertSame('warn', $row['status']);
        $this->assertContains('missing: Referrer-Policy', $row['items']);
    }

    public function test_reading_the_live_site_warns_about_a_header_sent_twice_by_the_web_server(): void
    {
        $headers = self::HEADERS_ON;
        $headers['X-Frame-Options'] = ['SAMEORIGIN', 'SAMEORIGIN'];
        Http::fake(['https://shop.example*' => Http::response('<p>ok</p>', 200, $headers)]);

        $row = $this->doctorRow('security_headers_live', ['--probe-headers' => true]);

        $this->assertSame('warn', $row['status']);
        $this->assertContains('sent twice: X-Frame-Options', $row['items']);
    }

    public function test_reading_the_live_site_warns_when_a_header_switched_off_still_arrives(): void
    {
        $this->setSecuritySettings([SecurityHeaderSettings::FRAME => 0]);
        Http::fake(['https://shop.example*' => Http::response('<p>ok</p>', 200, self::HEADERS_ON)]);

        $row = $this->doctorRow('security_headers_live', ['--probe-headers' => true]);

        $this->assertSame('warn', $row['status']);
        $this->assertContains('switched off but still sent: X-Frame-Options', $row['items']);
    }

    public function test_a_site_that_cannot_be_reached_is_reported_as_not_checked_not_as_an_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('blocked by host'));

        $row = $this->doctorRow('security_headers_live', ['--probe-headers' => true]);

        $this->assertSame('pass', $row['status']);
        $this->assertStringContainsString('could not check', $row['detail']);
    }

    public function test_a_redirect_to_another_host_is_not_followed(): void
    {
        Http::fake([
            'https://shop.example*' => Http::response('', 302, ['Location' => 'https://elsewhere.example/']),
            'https://elsewhere.example*' => Http::response('<p>x</p>', 200, self::HEADERS_ON),
        ]);

        $row = $this->doctorRow('security_headers_live', ['--probe-headers' => true]);

        $this->assertStringContainsString('could not check', $row['detail']);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://elsewhere.example'));
    }
}
