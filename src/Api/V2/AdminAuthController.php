<?php

namespace GP247\Core\Api\V2;

use GP247\Core\Api\Controllers\AdminAuthController as V1AdminAuthController;
use Illuminate\Http\Request;

/**
 * Admin authentication for api/v2/core: the same login/logout as v1 (throttle,
 * token lifetime, status check), answered inside the v2 `{"data": …}` envelope.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-v2-contract
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class AdminAuthController extends V1AdminAuthController
{
    /**
     * Issue a token: {"data": {access_token, token_type, scopes, expires_at}}.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $response = parent::login($request);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        return response()->json(['data' => $response->getData(true)]);
    }

    /**
     * Revoke the current token (POST only in v2).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['data' => ['revoked' => true]]);
    }

    /**
     * Profile of the signed-in admin.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
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
