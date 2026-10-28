<?php

namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One refresh token of an API session. Only its SHA-256 hash is stored; it can be
 * exchanged once (used_at), after which presenting it again is treated as reuse.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-refresh-token-rotation
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class AdminApiRefreshToken extends Model
{
    public $table = GP247_DB_PREFIX . 'api_refresh_token';
    protected $connection = GP247_DB_CONNECTION;
    public const UPDATED_AT = null;
    protected $guarded = ['id'];
    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    /**
     * Session the token belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function session()
    {
        return $this->belongsTo(AdminApiSession::class, 'session_id');
    }
}
