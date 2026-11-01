<?php

namespace GP247\Core\Api\Sync;

use GP247\Core\Models\AdminApiWebhook;
use GP247\Core\Models\AdminApiWebhookDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook delivery without a worker: a recorded event queues one delivery row per active
 * webhook following its type, and the deliveries run after the response is sent
 * (`defer()`) — in the request that recorded the event and in later requests while a
 * retry is due. One run at a time, at most 5 sends and 2 seconds per run; a failure is
 * retried after 1 min, 5 min, 30 min, 2 h and 12 h, then given up; a webhook failing 50
 * times in a row is turned off. Sites without webhooks pay one cache read per event.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-delivery
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
final class WebhookDispatcher
{
    /** Cache key of the active webhooks: id => followed event types. */
    public const WEBHOOKS_KEY = 'gp247:api-webhooks';

    /** Cache key of the unix time the earliest pending delivery is due. */
    public const DUE_KEY = 'gp247:api-webhook-due';

    private const LOCK = 'gp247:api-webhook-run';

    /** Sends per run. */
    public const MAX_SENDS = 5;

    /** Seconds one run may spend sending (NFR-PERF-api-sync-webhook-latency). */
    public const BUDGET_SECONDS = 2.0;

    /** Minutes before each retry; one more failure after the last gives up. */
    public const RETRY_MINUTES = [1, 5, 30, 120, 720];

    /** Failures in a row that turn a webhook off. */
    public const DISABLE_AFTER = 50;

    /**
     * Queue $eventId for the active webhooks following $type, and schedule a run.
     * Called by ApiEvents right after the event row is written.
     *
     * @param int    $eventId
     * @param string $type
     * @return void
     */
    public static function enqueue(int $eventId, string $type): void
    {
        $rows = [];
        $now = now();
        foreach (self::activeWebhooks() as $webhookId => $types) {
            if (in_array($type, $types, true)) {
                $rows[] = ['webhook_id' => $webhookId, 'event_id' => $eventId, 'attempts' => 0, 'status' => AdminApiWebhookDelivery::PENDING,
                    'next_attempt_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows === []) {
            return;
        }
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_webhook_delivery')->insert($rows);
        self::markDue($now->getTimestamp());
        self::schedule();
    }

    /**
     * Make the pending deliveries due now (a webhook was turned back on).
     *
     * @return void
     */
    public static function wake(): void
    {
        Cache::forever(self::DUE_KEY, time());
    }

    /**
     * Schedule a run after this response when a delivery is due (cheap cache check).
     *
     * @return void
     */
    public static function kickIfDue(): void
    {
        try {
            $due = Cache::get(self::DUE_KEY);
            if ($due !== null && (int) $due <= time()) {
                self::schedule();
            }
        } catch (\Throwable $e) {
            gp247_report($e);
        }
    }

    /**
     * Send the due deliveries — at most MAX_SENDS within $budget seconds.
     *
     * @param float $budget Seconds this run may spend.
     * @return int Number of sends attempted.
     */
    public static function run(float $budget = self::BUDGET_SECONDS): int
    {
        if (!self::ready() || !Cache::add(self::LOCK, 1, 30)) {
            return 0;
        }
        $sent = 0;
        try {
            $deadline = microtime(true) + $budget;
            $due = AdminApiWebhookDelivery::query()
                ->where('status', AdminApiWebhookDelivery::PENDING)
                ->where('next_attempt_at', '<=', now())
                ->whereIn('webhook_id', array_keys(self::activeWebhooks()))
                ->orderBy('id')->limit(self::MAX_SENDS)->get();
            foreach ($due as $delivery) {
                $remaining = $deadline - microtime(true);
                if ($remaining < 0.2) {
                    break;
                }
                self::deliver($delivery, min($remaining, self::BUDGET_SECONDS));
                $sent++;
            }
            self::refreshDue();
        } catch (\Throwable $e) {
            gp247_report($e);
        } finally {
            Cache::forget(self::LOCK);
        }

        return $sent;
    }

    /**
     * Drop the cached webhook list (after any change of a webhook).
     *
     * @return void
     */
    public static function forgetWebhooks(): void
    {
        Cache::forget(self::WEBHOOKS_KEY);
    }

    /**
     * Active webhooks: id => followed event types (cached; empty before gp247:update).
     *
     * @return array<int, string[]>
     */
    public static function activeWebhooks(): array
    {
        return Cache::rememberForever(self::WEBHOOKS_KEY, function () {
            if (!self::ready()) {
                return [];
            }
            $out = [];
            foreach (AdminApiWebhook::where('status', 1)->get(['id', 'event_types']) as $webhook) {
                $out[(int) $webhook->id] = array_values((array) $webhook->event_types);
            }
            return $out;
        });
    }

    /**
     * One send of $delivery, then its new state.
     *
     * @param AdminApiWebhookDelivery $delivery
     * @param float                   $timeout Seconds.
     * @return void
     */
    private static function deliver(AdminApiWebhookDelivery $delivery, float $timeout): void
    {
        $webhook = AdminApiWebhook::find($delivery->webhook_id);
        $event = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_event')->where('id', $delivery->event_id)->first();
        if ($webhook === null || $event === null) {
            // The event was cleaned or the webhook removed: nothing left to send.
            $delivery->update(['status' => AdminApiWebhookDelivery::FAILED, 'last_error' => 'gone']);
            return;
        }

        [$code, $error] = self::send($webhook, $event, $timeout);
        $attempts = $delivery->attempts + 1;
        if ($code !== null && $code >= 200 && $code < 300) {
            $delivery->update(['status' => AdminApiWebhookDelivery::DELIVERED, 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => null]);
            if ($webhook->consecutive_failures !== 0) {
                $webhook->update(['consecutive_failures' => 0]);
            }
            return;
        }

        $retryIn = self::RETRY_MINUTES[$attempts - 1] ?? null;
        $delivery->update([
            'status' => $retryIn === null ? AdminApiWebhookDelivery::FAILED : AdminApiWebhookDelivery::PENDING,
            'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => $error !== null ? mb_substr($error, 0, 255) : null,
            'next_attempt_at' => $retryIn === null ? null : now()->addMinutes($retryIn),
        ]);
        $failures = $webhook->consecutive_failures + 1;
        if ($failures >= self::DISABLE_AFTER) {
            $webhook->update(['consecutive_failures' => $failures, 'status' => 0, 'disabled_reason' => 'too_many_failures ' . now()->toIso8601String()]);
            self::forgetWebhooks();
        } else {
            $webhook->update(['consecutive_failures' => $failures]);
        }
    }

    /**
     * POST the event to the webhook URL, pinned to the addresses just checked.
     *
     * @param AdminApiWebhook $webhook
     * @param object          $event   api_event row.
     * @param float           $timeout
     * @return array{0: int|null, 1: string|null} HTTP status (null when nothing came back) and error.
     */
    private static function send(AdminApiWebhook $webhook, object $event, float $timeout): array
    {
        try {
            $target = OutboundUrlGuard::check((string) $webhook->url);
        } catch (OutboundUrlRejected $e) {
            return [null, $e->getMessage()];
        }
        $body = json_encode(EventPayload::fromRow($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!defined('CURLOPT_RESOLVE')) {
            // WHY fail closed: without cURL the connection cannot be pinned to the checked addresses.
            return [null, 'curl_missing'];
        }
        // WHY pin: resolving again inside the HTTP client could get a different (internal) address.
        $options = ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => array_map(
            fn ($ip) => $target['host'] . ':' . $target['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip),
            $target['ips']
        )]];
        try {
            $response = Http::withOptions($options)
                ->connectTimeout(max(0.2, min(1.0, $timeout)))
                ->timeout(max(0.2, $timeout))
                ->withHeaders([
                    'User-Agent' => 'GP247-Webhook/1.0',
                    'X-GP247-Event-Id' => (string) $event->id,
                    'X-GP247-Event-Type' => (string) $event->type,
                    WebhookSignature::HEADER => WebhookSignature::sign((string) $webhook->secret, time(), $body),
                ])
                ->withBody($body, 'application/json')
                ->post((string) $webhook->url);

            return [$response->status(), $response->successful() ? null : 'http_' . $response->status()];
        } catch (\Throwable $e) {
            return [null, 'connection: ' . $e->getMessage()];
        }
    }

    /**
     * @param int $timestamp
     * @return void
     */
    private static function markDue(int $timestamp): void
    {
        $current = Cache::get(self::DUE_KEY);
        if ($current === null || (int) $current > $timestamp) {
            Cache::forever(self::DUE_KEY, $timestamp);
        }
    }

    /**
     * Point the due marker at the earliest pending delivery, or drop it.
     *
     * @return void
     */
    private static function refreshDue(): void
    {
        $next = AdminApiWebhookDelivery::query()->where('status', AdminApiWebhookDelivery::PENDING)
            ->whereIn('webhook_id', array_keys(self::activeWebhooks()))->min('next_attempt_at');
        if ($next === null) {
            Cache::forget(self::DUE_KEY);
        } else {
            Cache::forever(self::DUE_KEY, \Illuminate\Support\Carbon::parse($next)->getTimestamp());
        }
    }

    /**
     * Run once after this response.
     *
     * @return void
     */
    private static function schedule(): void
    {
        // WHY a container flag (not a static): it lives exactly as long as this request's app.
        if (app()->bound('gp247.webhook_run_scheduled')) {
            return;
        }
        app()->instance('gp247.webhook_run_scheduled', true);
        defer(fn () => self::run());
    }

    /**
     * @return bool Whether the webhook tables exist (false until gp247:update ran).
     */
    private static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'api_webhook_delivery');
    }
}
