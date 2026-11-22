<?php

namespace GP247\Core\Tests\Unit\ApiSync;

use GP247\Core\Api\Sync\ChangeCursor;
use GP247\Core\Api\Sync\IdempotencyGuard;
use PHPUnit\Framework\TestCase;

/**
 * Cursor of the changed-since mode and the request fingerprint of the idempotency store.
 */
class ChangeCursorTest extends TestCase
{
    public function test_a_cursor_round_trips(): void
    {
        $cursor = ChangeCursor::encode('2037-06-01 10:00:00', 'PRD-AbC12');

        $this->assertSame(['2037-06-01 10:00:00', 'PRD-AbC12'], ChangeCursor::decode($cursor));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $cursor, 'URL-safe without padding');
    }

    public function test_forged_or_broken_cursors_decode_to_null(): void
    {
        foreach (['', 'not-a-cursor', base64_encode('2037-06-01|x'), rtrim(strtr(base64_encode('yesterday|PRD-1'), '+/', '-_'), '='),
                  rtrim(strtr(base64_encode('2037-06-01 10:00:00|'), '+/', '-_'), '='), str_repeat('A', 400)] as $bad) {
            $this->assertNull(ChangeCursor::decode($bad), 'cursor ' . $bad);
        }
    }

    public function test_the_request_fingerprint_ignores_key_order(): void
    {
        $a = IdempotencyGuard::fingerprint('POST', 'api/v2/core/x', ['b' => 2, 'a' => ['y' => 1, 'x' => 0]]);
        $b = IdempotencyGuard::fingerprint('POST', 'api/v2/core/x', ['a' => ['x' => 0, 'y' => 1], 'b' => 2]);
        $c = IdempotencyGuard::fingerprint('POST', 'api/v2/core/x', ['a' => ['x' => 0, 'y' => 1], 'b' => 3]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }
}
