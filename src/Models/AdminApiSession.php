<?php

namespace GP247\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One REST API v2 sign-in on one device: the access token it currently holds and,
 * when the client asked for one, the refresh-token chain rotating inside it.
 * Revoking the session deletes its access token, so it stops working on the next
 * request.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-session-management, US-CMP-api-refresh-token-rotation
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class AdminApiSession extends Model
{
    public $table = GP247_DB_PREFIX . 'api_session';
    protected $connection = GP247_DB_CONNECTION;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['id'];
    protected $casts = [
        'refreshable'        => 'boolean',
        'refresh_expires_at' => 'datetime',
        'last_used_at'       => 'datetime',
        'revoked_at'         => 'datetime',
    ];

    /**
     * Give every new session a UUID.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::creating(function (self $session) {
            $session->id = $session->id ?: (string) Str::uuid();
        });
    }

    /**
     * Refresh tokens issued inside this session.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function refreshTokens()
    {
        return $this->hasMany(AdminApiRefreshToken::class, 'session_id');
    }

    /**
     * Whether the session can still be used (not revoked, refresh window open).
     *
     * @return bool
     */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        return !$this->refreshable || ($this->refresh_expires_at !== null && $this->refresh_expires_at->isFuture());
    }
}
