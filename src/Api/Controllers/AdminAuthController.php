<?php

namespace GP247\Core\Api\Controllers;

use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Api\Support\ApiLoginThrottle;
use GP247\Core\Api\Support\ApiTokenLifetime;
use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class AdminAuthController extends RootAdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Login user and create token
     *
     * Failed attempts are limited per (username + IP); the token always expires
     * (remember_me picks the longer lifetime).
     *
     * @param  [string] username
     * @param  [string] password
     * @param  [boolean] remember_me
     * @return [string] access_token
     * @return [string] token_type
     * @return [string] expires_at
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-api-login-throttle, US-CMP-api-token-lifetime
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'remember_me' => 'boolean'
        ]);

        $username = (string) $request->input('username');
        $subject = ApiLoginThrottle::subject(AdminUser::where('username', $username)->value('id'), $username);
        if ($blocked = ApiLoginThrottle::attempt($request, 'admin', $subject)) {
            return $blocked;
        }

        $credentials = request(['username', 'password']);
        $credentials['status'] = 1;

        if (!$this->guard()->attempt($credentials)) {
            return ApiErrorResponder::respond(401, 'unauthenticated', 'Unauthorized');
        }
        ApiLoginThrottle::succeeded($request, 'admin', $subject);

        $user = $this->guard()->user();

        if (function_exists('gp247_event_admin_login')) {
            gp247_event_admin_login($user);
        }
        if ($user->isRole('administrator')) {
            $scope = explode(',', config('gp247-config.api.auth.api_scope_admin_supper'));
        } else {
            $scope = explode(',', config('gp247-config.api.auth.api_scope_admin'));
        }

        ApiTokenLifetime::purgeExpired($user);
        $days = $request->remember_me
            ? config('gp247-config.api.auth.api_remmember_admin')
            : config('gp247-config.api.auth.api_token_expire_admin_default');
        $tokenResult = $user->createToken('Admin:'.$user->email.'- '.now(), $scope, ApiTokenLifetime::expiresAt($days));
        $token = $tokenResult->plainTextToken;
        $accessToken = $tokenResult->accessToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'scopes' => $accessToken->abilities,
            'expires_at' => Carbon::parse(
                $accessToken->expires_at
            )->toDateTimeString()
        ]);
    }

    /**
     * Logout user (Revoke the token)
     *
     * POST is the supported method; the v1 GET route answers the same and adds a
     * Deprecation header.
     *
     * @return [string] message
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-api-token-lifetime
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return ApiTokenLifetime::withLogoutDeprecation($request, response()->json([
            'error' => 0,
            'msg' => 'Successfully logged out'
        ]));
    }

    protected function guard()
    {
        return Auth::guard('admin');
    }
}
