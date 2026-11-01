<?php

namespace GP247\Core\Api\Sync;

/**
 * Signature of a webhook body: `t=<unix time>,v1=<hex HMAC-SHA256(secret, t . "." . body)>`
 * in the `X-GP247-Signature` header. The receiver recomputes it with its copy of the
 * secret and refuses an old timestamp, so a captured request cannot be replayed later.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-delivery
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
final class WebhookSignature
{
    public const HEADER = 'X-GP247-Signature';

    /**
     * @param string $secret
     * @param int    $timestamp Unix time of the send.
     * @param string $body      Raw JSON body.
     * @return string Header value.
     */
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * Check a header value as a receiver does.
     *
     * @param string   $secret
     * @param string   $header    Value of X-GP247-Signature.
     * @param string   $body      Raw body as received.
     * @param int      $tolerance Seconds a timestamp stays valid.
     * @param int|null $now       Unix time (defaults to now).
     * @return bool
     */
    public static function verify(string $secret, string $header, string $body, int $tolerance = 300, ?int $now = null): bool
    {
        if (!preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $header, $m)) {
            return false;
        }
        $timestamp = (int) $m[1];
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $m[2]);
    }
}
