<?php

namespace GP247\Core\Api\Sync;

/**
 * A URL OutboundUrlGuard refuses to call; the message is the reason code
 * (`not_https`, `credentials`, `port`, `unresolvable`, `blocked_address`).
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-register
 */
final class OutboundUrlRejected extends \RuntimeException
{
}
