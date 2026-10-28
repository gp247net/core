<?php

namespace GP247\Core\Api\Support;

use GP247\Core\Models\AdminApiRefreshToken;
use GP247\Core\Models\AdminApiSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * REST API v2 sessions: one per sign-in, holding the live access token and, when the
 * client asked with_refresh, a rotating refresh token. Revocation deletes access tokens
 * (Sanctum refuses them on the next request), so nothing is checked per request and no
 * scheduler is needed — stale rows are purged when their owner signs in again.
 *
 * Works on sites that have not run gp247:update yet: without the tables, login issues a
 * plain token as before and revokeAll() still deletes the owner's tokens.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-session-management, US-CMP-api-refresh-token-rotation, US-CMP-api-token-lifetime
 * @aidlc-adr compat-foundation_api-token-rotation
 */
final class ApiSessions
{
    /** Revoked/expired sessions older than this are deleted at the owner's next sign-in. */
    private const PURGE_AFTER_DAYS = 7;

    /** @var bool|null Memo of the schema probe (one per process). */
    private static ?bool $ready = null;

    /**
     * Whether the session tables exist.
     *
     * @return bool
     */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            $schema = Schema::connection(GP247_DB_CONNECTION);
            self::$ready = $schema->hasTable(GP247_DB_PREFIX . 'api_session')
                && $schema->hasTable(GP247_DB_PREFIX . 'api_refresh_token');
        }
        return self::$ready;
    }

    /**
     * Drop the schema memo (after a migration in the same process).
     *
     * @return void
     */
    public static function forgetSchemaMemo(): void
    {
        self::$ready = null;
    }

    /**
     * Lifetime of an access token issued together with a refresh token, in minutes.
     *
     * @return int
     */
    public static function accessMinutes(): int
    {
        return max(5, (int) config('gp247-config.api.auth.api_access_minutes', 60));
    }

    /**
     * Sign $owner in: issue the access token and, on v2 with the tables present, open a
     * session (plus a refresh token when $opts['with_refresh']).
     *
     * @param Model   $owner    Customer or admin (HasApiTokens).
     * @param string  $audience "customer" or "admin".
     * @param Request $request
     * @param array{abilities: string[], name: string, days: int, refresh_days: int, with_refresh?: bool, device_name?: ?string} $opts
     * @return array<string, mixed> Response data: access_token, token_type, scopes, expires_at, expires_in
     *                              [, session_id, refresh_token, refresh_expires_at].
     */
    public static function open(Model $owner, string $audience, Request $request, array $opts): array
    {
        ApiTokenLifetime::purgeExpired($owner);
        $withRefresh = !empty($opts['with_refresh']) && self::ready();
        $expiresAt = $withRefresh
            ? now()->addMinutes(self::accessMinutes())
            : ApiTokenLifetime::expiresAt($opts['days']);
        $result = $owner->createToken($opts['name'], $opts['abilities'], $expiresAt);
        $data = self::tokenData($result->plainTextToken, $result->accessToken);
        if (!self::ready()) {
            return $data;
        }

        self::purgeStale($owner);
        $session = AdminApiSession::create([
            'tokenable_type'     => $owner->getMorphClass(),
            'tokenable_id'       => (string) $owner->getKey(),
            'audience'           => $audience,
            'name'               => Str::limit((string) (($opts['device_name'] ?? '') ?: $request->userAgent()), 97),
            'ip'                 => $request->ip(),
            'user_agent'         => Str::limit((string) $request->userAgent(), 252),
            'abilities'          => json_encode(array_values($opts['abilities'])),
            'access_token_id'    => $result->accessToken->id,
            'refreshable'        => $withRefresh,
            'refresh_expires_at' => $withRefresh ? now()->addDays(max(1, (int) $opts['refresh_days'])) : null,
            'last_used_at'       => now(),
        ]);
        $data['session_id'] = $session->id;
        if ($withRefresh) {
            $data['refresh_token'] = ApiRefreshTokens::issue($session);
            $data['refresh_expires_at'] = $session->refresh_expires_at->toDateTimeString();
        }
        return $data;
    }

    /**
     * Response data for a freshly issued access token.
     *
     * @param string              $plain
     * @param PersonalAccessToken $token
     * @return array<string, mixed>
     */
    public static function tokenData(string $plain, $token): array
    {
        return [
            'access_token' => $plain,
            'token_type'   => 'Bearer',
            'scopes'       => $token->abilities,
            'expires_at'   => $token->expires_at?->toDateTimeString(),
            'expires_in'   => $token->expires_at ? max(0, (int) now()->diffInSeconds($token->expires_at, false)) : null,
        ];
    }

    /**
     * Session that holds $token, if any and not revoked.
     *
     * @param PersonalAccessToken|null $token
     * @return AdminApiSession|null
     */
    public static function forToken($token): ?AdminApiSession
    {
        if (!$token instanceof PersonalAccessToken || !self::ready()) {
            return null;
        }
        return AdminApiSession::where('access_token_id', $token->id)
            ->where('tokenable_type', $token->tokenable_type)
            ->where('tokenable_id', (string) $token->tokenable_id)
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * Revoke one session: its access token stops working at once, its refresh tokens
     * can no longer be exchanged.
     *
     * @param AdminApiSession $session
     * @param string          $reason  logout | user | password_changed | reuse_detected
     * @return void
     */
    public static function revoke(AdminApiSession $session, string $reason): void
    {
        $session->forceFill(['revoked_at' => now()])->save();
        if ($session->access_token_id) {
            self::tokenModel()::whereKey($session->access_token_id)->delete();
        }
        if ($reason === 'reuse_detected') {
            // WHY: a replayed refresh token is the signature of a stolen one.
            gp247_report('API refresh token reused; session revoked (session ' . $session->id . ', audience ' . $session->audience . ')');
        }
    }

    /**
     * Revoke every API credential of $owner — v2 sessions and every Sanctum token,
     * including v1 tokens that have no session — except the access token $keepTokenId
     * (the session changing the password through the API).
     *
     * @param Model    $owner
     * @param int|null $keepTokenId
     * @return void
     */
    public static function revokeAll(Model $owner, ?int $keepTokenId = null): void
    {
        // WHY sessions first: a refresh in flight re-reads its session after issuing the new
        // token (ApiRefreshTokens::rotate), so marking before deleting leaves no live token.
        if (self::ready()) {
            self::ownedBy($owner)
                ->whereNull('revoked_at')
                ->when($keepTokenId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('access_token_id')
                    ->orWhere('access_token_id', '!=', $keepTokenId)))
                ->update(['revoked_at' => now()]);
        }
        $owner->tokens()
            ->when($keepTokenId !== null, fn ($q) => $q->where('id', '!=', $keepTokenId))
            ->delete();
    }

    /**
     * Live sessions of $owner for the "my devices" list (no token material).
     *
     * @param Model    $owner
     * @param int|null $currentTokenId Access token of the caller, to flag its session.
     * @return array<int, array<string, mixed>>
     */
    public static function listFor(Model $owner, ?int $currentTokenId): array
    {
        if (!self::ready()) {
            return [];
        }
        $sessions = self::ownedBy($owner)->whereNull('revoked_at')->orderByDesc('created_at')->get();
        $tokens = self::tokenModel()::whereIn('id', $sessions->pluck('access_token_id')->filter())->get()->keyBy('id');

        return $sessions->filter(function (AdminApiSession $s) use ($tokens) {
            $token = $tokens->get($s->access_token_id);
            $tokenAlive = $token && (!$token->expires_at || $token->expires_at->isFuture());
            return $s->refreshable ? $s->isActive() : $tokenAlive;
        })->map(function (AdminApiSession $s) use ($tokens, $currentTokenId) {
            $tokenUsed = optional($tokens->get($s->access_token_id))->last_used_at;
            $lastUsed = collect([$s->last_used_at, $tokenUsed])->filter()->max();
            return [
                'id'                 => $s->id,
                'name'               => $s->name,
                'ip'                 => $s->ip,
                'created_at'         => optional($s->created_at)->toIso8601String(),
                'last_used_at'       => $lastUsed ? $lastUsed->toIso8601String() : null,
                'refreshable'        => (bool) $s->refreshable,
                'refresh_expires_at' => optional($s->refresh_expires_at)->toIso8601String(),
                'current'            => $currentTokenId !== null && (int) $s->access_token_id === $currentTokenId,
            ];
        })->values()->all();
    }

    /**
     * A live session of $owner by id, or null (another owner's id is "not found").
     *
     * @param Model  $owner
     * @param string $id
     * @return AdminApiSession|null
     */
    public static function findFor(Model $owner, string $id): ?AdminApiSession
    {
        if (!self::ready()) {
            return null;
        }
        return self::ownedBy($owner)->whereKey($id)->whereNull('revoked_at')->first();
    }

    /**
     * Model that owns $session, or null when it no longer exists.
     *
     * @param AdminApiSession $session
     * @return Model|null
     */
    public static function owner(AdminApiSession $session): ?Model
    {
        $class = Relation::getMorphedModel($session->tokenable_type) ?? $session->tokenable_type;
        if (!is_string($class) || !class_exists($class)) {
            return null;
        }
        return $class::find($session->tokenable_id);
    }

    /**
     * Sanctum token model in use (GP247 swaps in its own model and connection).
     *
     * @return class-string<PersonalAccessToken>
     */
    public static function tokenModel(): string
    {
        return \Laravel\Sanctum\Sanctum::personalAccessTokenModel();
    }

    /**
     * Query of the sessions owned by $owner.
     *
     * @param Model $owner
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private static function ownedBy(Model $owner)
    {
        return AdminApiSession::where('tokenable_type', $owner->getMorphClass())
            ->where('tokenable_id', (string) $owner->getKey());
    }

    /**
     * Delete sessions of $owner revoked, or out of their refresh window, for longer than
     * PURGE_AFTER_DAYS, together with their refresh tokens (lazy clean-up, no cron).
     *
     * @param Model $owner
     * @return void
     */
    private static function purgeStale(Model $owner): void
    {
        $limit = now()->subDays(self::PURGE_AFTER_DAYS);
        $ids = self::ownedBy($owner)
            ->where(fn ($q) => $q->where('revoked_at', '<', $limit)
                ->orWhere(fn ($r) => $r->where('refreshable', true)->where('refresh_expires_at', '<', $limit))
                ->orWhere(fn ($r) => $r->where('refreshable', false)->where('created_at', '<', $limit)
                    ->whereNotIn('access_token_id', self::tokenModel()::select('id'))))
            ->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        AdminApiRefreshToken::whereIn('session_id', $ids)->delete();
        AdminApiSession::whereIn('id', $ids)->delete();
    }
}
