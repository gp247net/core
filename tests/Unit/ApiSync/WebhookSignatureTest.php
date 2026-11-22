<?php

namespace GP247\Core\Tests\Unit\ApiSync;

use GP247\Core\Api\Sync\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * A receiver can check a webhook signature with its copy of the secret; a changed body,
 * another secret or an old timestamp fail.
 */
class WebhookSignatureTest extends TestCase
{
    public function test_a_receiver_checks_the_signature_and_refuses_a_tampered_body_another_secret_or_an_old_timestamp(): void
    {
        $body = '{"id":7,"type":"order.created"}';
        $header = WebhookSignature::sign('zz-secret', 1_800_000_000, $body);

        $this->assertMatchesRegularExpression('/^t=1800000000,v1=[0-9a-f]{64}$/', $header);
        $this->assertSame('t=1800000000,v1=' . hash_hmac('sha256', '1800000000.' . $body, 'zz-secret'), $header);
        $this->assertTrue(WebhookSignature::verify('zz-secret', $header, $body, 300, 1_800_000_100));
        $this->assertFalse(WebhookSignature::verify('zz-secret', $header, $body . ' ', 300, 1_800_000_100));
        $this->assertFalse(WebhookSignature::verify('other', $header, $body, 300, 1_800_000_100));
        $this->assertFalse(WebhookSignature::verify('zz-secret', $header, $body, 300, 1_800_000_301));
        $this->assertFalse(WebhookSignature::verify('zz-secret', 'garbage', $body));
    }
}
