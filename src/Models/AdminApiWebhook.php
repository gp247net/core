<?php

namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A webhook an administrator registered on the API screen: the URL that receives the sync
 * events of the chosen types, signed with its own secret. The secret is stored encrypted
 * with the application key and never serialised.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-webhook-register
 * @aidlc-adr api-sync_webhook-deferred-delivery
 */
class AdminApiWebhook extends Model
{
    public $table = GP247_DB_PREFIX . 'api_webhook';
    protected $connection = GP247_DB_CONNECTION;
    protected $fillable = ['url', 'event_types', 'secret', 'status', 'consecutive_failures', 'disabled_reason'];
    protected $hidden = ['secret'];
    protected $casts = ['event_types' => 'array', 'secret' => 'encrypted', 'status' => 'integer', 'consecutive_failures' => 'integer'];

    /**
     * A new random signing secret (64 hex characters).
     *
     * @return string
     */
    public static function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
