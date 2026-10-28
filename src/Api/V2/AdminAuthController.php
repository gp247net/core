<?php

namespace GP247\Core\Api\V2;

use GP247\Core\Api\Controllers\AdminAuthController as V1AdminAuthController;
use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Api\Support\ApiLoginThrottle;
use GP247\Core\Api\Support\ApiRefreshTokens;
use GP247\Core\Api\Support\ApiSessions;
use GP247\Core\Api\Support\LoginChallenges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin authentication for api/v2/core: the v1 credential rules (throttle, status
 * check, token lifetime) plus API sessions — optional rotating refresh tokens, a
 * plugin sign-in challenge, "my sessions" and logout that revokes the whole session.
 * Answers inside the v2 `{"data": …}` envelope.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-v2-contract, US-CMP-api-refresh-token-rotation, US-CMP-api-session-management, US-CMP-api-login-challenge-seam
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class AdminAuthController extends V1AdminAuthController
{
    private const AUDIENCE = 'admin';

    /**
     * Sign in; `with_refresh: true` adds a refresh token and a short-lived access token.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate(['with_refresh' => 'boolean', 'device_name' => 'nullable|string|max:100']);
        $user = $this->authenticate($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $opts = $this->tokenOptions($user, $request) + [
            'with_refresh' => $request->boolean('with_refresh'),
            'device_name'  => $request->input('device_name'),
        ];
        if ($challenge = LoginChallenges::gate($user, self::AUDIENCE, $request, $opts)) {
            return $challenge;
        }
        ApiLoginThrottle::succeeded($request, self::AUDIENCE, $this->throttleSubject($request));
        $this->signedIn($user);

        return response()->json(['data' => ApiSessions::open($user, self::AUDIENCE, $request, $opts)]);
    }

    /**
     * Finish a sign-in that required a plugin challenge.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function challenge(Request $request)
    {
        $result = LoginChallenges::complete($request, self::AUDIENCE);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        [$user, $opts] = $result;
        $this->signedIn($user);

        return response()->json(['data' => ApiSessions::open($user, self::AUDIENCE, $request, $opts)]);
    }

    /**
     * Exchange a refresh token for a new access + refresh pair.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request)
    {
        $result = ApiRefreshTokens::rotate($request, self::AUDIENCE);

        return $result instanceof JsonResponse ? $result : response()->json(['data' => $result]);
    }

    /**
     * Revoke the current session (access and refresh token); POST only in v2.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        $session = ApiSessions::forToken($token);
        if ($session) {
            ApiSessions::revoke($session, 'logout');
        } else {
            $token->delete();
        }

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * Live API sessions of the signed-in admin.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sessions(Request $request)
    {
        $user = $request->user();

        return response()->json(['data' => ApiSessions::listFor($user, (int) $user->currentAccessToken()->id)]);
    }

    /**
     * Revoke one of the signed-in admin's sessions.
     *
     * @param Request $request
     * @param string  $id
     * @return JsonResponse
     */
    public function revokeSession(Request $request, $id)
    {
        $session = ApiSessions::findFor($request->user(), (string) $id);
        if (!$session) {
            return ApiErrorResponder::notFound();
        }
        ApiSessions::revoke($session, 'user');

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * Profile of the signed-in admin.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function info(Request $request)
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id'         => $user->id,
            'username'   => $user->username,
            'name'       => $user->name,
            'email'      => $user->email,
            'avatar'     => $user->avatar,
            'status'     => (int) $user->status,
            'roles'      => $user->roles->pluck('slug')->values(),
            'created_at' => optional($user->created_at)->toIso8601String(),
            'updated_at' => optional($user->updated_at)->toIso8601String(),
        ]]);
    }
}
