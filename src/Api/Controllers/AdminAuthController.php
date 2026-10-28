<?php

namespace GP247\Core\Api\Controllers;

use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\Api\Support\ApiLoginThrottle;
use GP247\Core\Api\Support\LoginChallenges;
use GP247\Core\Api\Support\ApiTokenLifetime;
use GP247\Core\Controllers\RootAdminController;
use GP247\Core\Models\AdminUser;
use Illuminate\Http\JsonResponse;
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
     * (remember_me picks the longer lifetime). A sign-in challenge registered by a
     * plugin cannot be completed on v1, so it is refused with `challenge_required`.
     *
     * @param  [string] username
     * @param  [string] password
     * @param  [boolean] remember_me
     * @return [string] access_token
     * @return [string] token_type
     * @return [string] expires_at
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-api-login-throttle, US-CMP-api-token-lifetime, US-CMP-api-login-challenge-seam
     */
    public function login(Request $request)
    {
        $user = $this->authenticate($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if ($refused = LoginChallenges::refuseForV1($user, 'admin', $request)) {
            return $refused;
        }
        ApiLoginThrottle::succeeded($request, 'admin', $this->throttleSubject($request));
        $this->signedIn($user);

        $opts = $this->tokenOptions($user, $request);
        ApiTokenLifetime::purgeExpired($user);
        $tokenResult = $user->createToken($opts['name'], $opts['abilities'], ApiTokenLifetime::expiresAt($opts['days']));
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
     * Validate the credentials of an active admin, under the login throttle.
     *
     * @param Request $request
     * @return AdminUser|JsonResponse The admin, or the 401/429 response.
     *
     * @aidlc-unit compat-foundation
     * @aidlc-story US-CMP-api-login-throttle, US-CMP-api-token-lifetime
     */
    protected function authenticate(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'remember_me' => 'boolean'
        ]);

        if ($blocked = ApiLoginThrottle::attempt($request, 'admin', $this->throttleSubject($request))) {
            return $blocked;
        }

        $credentials = request(['username', 'password']);
        $credentials['status'] = 1;

        if (!$this->guard()->attempt($credentials)) {
            return ApiErrorResponder::respond(401, 'unauthenticated', 'Unauthorized');
        }

        return $this->guard()->user();
    }

    /**
     * Admin "signed in" event, fired once the sign-in is complete (after any challenge).
     *
     * @param AdminUser $user
     * @return void
     */
    protected function signedIn(AdminUser $user): void
    {
        if (function_exists('gp247_event_admin_login')) {
            gp247_event_admin_login($user);
        }
    }

    /**
     * Login throttle subject of the typed username.
     *
     * @param Request $request
     * @return string
     */
    protected function throttleSubject(Request $request): string
    {
        $username = (string) $request->input('username');
        return ApiLoginThrottle::subject(AdminUser::where('username', $username)->value('id'), $username);
    }

    /**
     * Abilities, name and lifetimes of the token issued to $user.
     *
     * @param AdminUser $user
     * @param Request   $request
     * @return array{abilities: string[], name: string, days: int, refresh_days: int}
     */
    protected function tokenOptions(AdminUser $user, Request $request): array
    {
        $scope = $user->isRole('administrator')
            ? config('gp247-config.api.auth.api_scope_admin_super')
            : config('gp247-config.api.auth.api_scope_admin');

        return [
            'abilities'    => explode(',', (string) $scope),
            'name'         => 'Admin:' . $user->email . '- ' . now(),
            'days'         => (int) ($request->remember_me
                ? config('gp247-config.api.auth.api_remember_admin')
                : config('gp247-config.api.auth.api_token_expire_admin_default')),
            'refresh_days' => (int) config('gp247-config.api.auth.api_refresh_admin_days', 7),
        ];
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
