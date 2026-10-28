<?php

namespace GP247\Core\Api\Support;

use GP247\Core\Models\AdminApiRefreshToken;
use GP247\Core\Models\AdminApiSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Rotating refresh tokens of REST API v2 sessions (OAuth 2.0 Security BCP, RFC 9700):
 * each token is exchanged once for a new access + refresh pair; presenting a spent token
 * again revokes the whole session, except within a short grace window that absorbs a
 * client retrying the same call.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-refresh-token-rotation
 * @aidlc-adr compat-foundation_api-token-rotation
 */
final class ApiRefreshTokens
{
    /** Replay of a spent token within this many seconds answers 409 without revoking. */
    public const GRACE_SECONDS = 10;

    /**
     * Issue a refresh token for $session; only its SHA-256 hash is stored.
     *
     * @param AdminApiSession $session
     * @return string Plain token, shown once to the client.
     */
    public static function issue(AdminApiSession $session): string
    {
        $plain = Str::random(64);
        AdminApiRefreshToken::create([
            'session_id' => $session->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $session->refresh_expires_at,
        ]);
        return $plain;
    }

    /**
     * Exchange a refresh token for a new pair.
     *
     * @param Request $request  Carries `refresh_token`.
     * @param string  $audience "customer" (api/v2/front) or "admin" (api/v2/core).
     * @return array<string, mixed>|JsonResponse Token data, or the error response.
     */
    public static function rotate(Request $request, string $audience)
    {
        $request->validate(['refresh_token' => 'required|string|max:255']);
        if (!ApiSessions::ready()) {
            return ApiErrorResponder::notFound();
        }

        $row = AdminApiRefreshToken::where('token_hash', hash('sha256', (string) $request->input('refresh_token')))->first();
        $session = $row ? $row->session : null;
        if (!$row || !$session || $session->audience !== $audience || $session->revoked_at !== null
            || $row->expires_at === null || $row->expires_at->isPast()) {
            return self::invalid();
        }

        $owner = ApiSessions::owner($session);
        // WHY: an inactive owner is refused without spending the token, so a re-opened
        // account keeps working on the same device.
        if (!$owner || (int) $owner->status !== 1) {
            return ApiErrorResponder::unauthenticated();
        }

        $claimed = AdminApiRefreshToken::whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]);
        if ($claimed !== 1) {
            $row->refresh();
            if ($row->used_at !== null && $row->used_at->diffInSeconds(now()) <= self::GRACE_SECONDS) {
                return ApiErrorResponder::respond(409, 'refresh_in_progress', 'This refresh token was just used; retry with the new one');
            }
            ApiSessions::revoke($session, 'reuse_detected');
            return ApiErrorResponder::respond(401, 'refresh_token_reused', 'Refresh token already used; the session was signed out');
        }

        $data = self::reissue($session, $owner);
        // WHY: a revoke (logout, password change) may have run while this refresh was in
        // flight; it marks the session first, so a re-read here catches it.
        $fresh = AdminApiSession::whereKey($session->id)->first();
        if (!$fresh || $fresh->revoked_at !== null) {
            ApiSessions::tokenModel()::whereKey(optional($fresh)->access_token_id)->delete();
            return self::invalid();
        }
        return $data;
    }

    /**
     * Replace the access token of $session and hand out the next refresh token.
     *
     * @param AdminApiSession                      $session
     * @param \Illuminate\Database\Eloquent\Model  $owner
     * @return array<string, mixed>
     */
    private static function reissue(AdminApiSession $session, $owner): array
    {
        $tokenModel = ApiSessions::tokenModel();
        $old = $session->access_token_id ? $tokenModel::find($session->access_token_id) : null;
        $abilities = json_decode((string) $session->abilities, true) ?: ($old ? $old->abilities : []);
        $name = $old ? $old->name : 'Api session ' . $session->id;
        if ($old) {
            $old->delete();
        }

        $result = $owner->createToken($name, $abilities, now()->addMinutes(ApiSessions::accessMinutes()));
        $session->forceFill(['access_token_id' => $result->accessToken->id, 'last_used_at' => now()])->save();

        $data = ApiSessions::tokenData($result->plainTextToken, $result->accessToken);
        $data['session_id'] = $session->id;
        $data['refresh_token'] = self::issue($session);
        $data['refresh_expires_at'] = $session->refresh_expires_at->toDateTimeString();
        return $data;
    }

    /**
     * 401 for an unknown, expired or revoked refresh token.
     *
     * @return JsonResponse
     */
    private static function invalid(): JsonResponse
    {
        return ApiErrorResponder::respond(401, 'refresh_token_invalid', 'Invalid or expired refresh token');
    }
}
