<?php

namespace GP247\Core\Tests\Unit\SecurityHeaders;

use GP247\Core\SecurityHeaders\Domain\HeaderPolicy;
use GP247\Core\SecurityHeaders\Domain\HstsSetting;
use GP247\Core\SecurityHeaders\Domain\ResponseKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Which header goes on which kind of response (logical design §5.1).
 *
 * @aidlc-unit security-headers
 * @aidlc-adr security-headers_response-middleware
 */
#[Group('security-headers')]
class HeaderPolicyTest extends TestCase
{
    public function test_html_gets_the_four_headers(): void
    {
        $headers = HeaderPolicy::defaults()->headersFor(ResponseKind::Html, false);

        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        $this->assertSame('camera=(), microphone=(), geolocation=()', $headers['Permissions-Policy']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $headers);
    }

    public function test_json_gets_no_frame_or_permissions_header(): void
    {
        $headers = HeaderPolicy::defaults()->headersFor(ResponseKind::Json, false);

        $this->assertSame(['X-Content-Type-Options', 'Referrer-Policy'], array_keys($headers));
    }

    public function test_file_gets_only_nosniff(): void
    {
        $this->assertSame(
            ['X-Content-Type-Options' => 'nosniff'],
            HeaderPolicy::defaults()->headersFor(ResponseKind::File, false)
        );
    }

    public function test_a_switched_off_header_is_not_sent(): void
    {
        $policy = new HeaderPolicy(frame: false, nosniff: true, referrer: false, permissions: true);

        $headers = $policy->headersFor(ResponseKind::Html, false);

        $this->assertArrayNotHasKey('X-Frame-Options', $headers);
        $this->assertArrayNotHasKey('Referrer-Policy', $headers);
        $this->assertArrayHasKey('X-Content-Type-Options', $headers);
    }

    public function test_hsts_is_sent_only_on_a_secure_request(): void
    {
        $policy = new HeaderPolicy(hsts: new HstsSetting(86400));

        foreach (ResponseKind::cases() as $kind) {
            $this->assertArrayNotHasKey('Strict-Transport-Security', $policy->headersFor($kind, false));
            $this->assertSame('max-age=86400', $policy->headersFor($kind, true)['Strict-Transport-Security']);
        }
    }

    public function test_a_permission_an_extension_asked_for_is_opened(): void
    {
        $policy = new HeaderPolicy(grantedPermissions: ['geolocation', 'not-a-feature']);

        $this->assertSame(
            'camera=(), microphone=(), geolocation=(self)',
            $policy->headersFor(ResponseKind::Html, false)['Permissions-Policy']
        );
    }

    public function test_every_feature_granted_is_opened_for_the_site_only(): void
    {
        $policy = new HeaderPolicy(grantedPermissions: ['camera', 'microphone', 'geolocation']);

        $this->assertSame(
            'camera=(self), microphone=(self), geolocation=(self)',
            $policy->headersFor(ResponseKind::Html, false)['Permissions-Policy']
        );
    }

    public function test_response_kind_is_read_from_the_response(): void
    {
        $this->assertSame(ResponseKind::Html, ResponseKind::of(new Response('<p>x</p>')));
        $this->assertSame(ResponseKind::Json, ResponseKind::of(new JsonResponse(['a' => 1], 422)));
        $this->assertSame(ResponseKind::File, ResponseKind::of(new StreamedResponse(fn () => null)));
        $this->assertSame(ResponseKind::File, ResponseKind::of(new BinaryFileResponse(__FILE__)));

        $attachment = new Response('a,b');
        $attachment->headers->set('Content-Disposition', 'attachment; filename="x.csv"');
        $this->assertSame(ResponseKind::File, ResponseKind::of($attachment));

        $problem = new Response('{}', 400, ['Content-Type' => 'application/problem+json']);
        $this->assertSame(ResponseKind::Json, ResponseKind::of($problem));
    }
}
