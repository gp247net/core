<?php

namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One event to deliver to one webhook — the queue and the delivery history at once.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-delivery
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
class AdminApiWebhookDelivery extends Model
{
    public const PENDING = 'pending';
    public const DELIVERED = 'delivered';
    public const FAILED = 'failed';

    public $table = GP247_DB_PREFIX . 'api_webhook_delivery';
    protected $connection = GP247_DB_CONNECTION;
    protected $fillable = ['webhook_id', 'event_id', 'attempts', 'status', 'next_attempt_at', 'last_status_code', 'last_error'];
    protected $casts = ['next_attempt_at' => 'datetime', 'attempts' => 'integer'];
}
