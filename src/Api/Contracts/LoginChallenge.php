<?php

namespace GP247\Core\Api\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Second sign-in step for the REST API (OTP, e-mail code, TOTP…) supplied by a plugin.
 * Register the class under `gp247-config.api.login_challenge.<customer|admin>`; core
 * issues a short-lived ticket after the password check and calls verify() when the
 * client answers on `POST login/challenge`. Core never generates or checks codes itself.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-login-challenge-seam
 * @aidlc-adr compat-foundation_api-token-rotation
 */
interface LoginChallenge
{
    /**
     * Whether $owner must pass this challenge before a token is issued. Must have no side
     * effect: it is also called on v1 sign-ins, which are refused without a ticket.
     *
     * @param Authenticatable $owner   Customer or admin whose password was just verified.
     * @param Request         $request The login request.
     * @return bool
     */
    public function required(Authenticatable $owner, Request $request): bool;

    /**
     * Called once when the ticket is issued (v2): send the code here (SMS, e-mail) if the
     * method needs one, and return public hints for the client, e.g.
     * ['method' => 'totp', 'meta' => ['digits' => 6]]. Must not return secrets.
     *
     * @param Authenticatable $owner
     * @return array{method?: string, meta?: array}
     */
    public function describe(Authenticatable $owner): array;

    /**
     * Check the client's answer; the fields are defined by the plugin and read from $request.
     *
     * @param Authenticatable $owner
     * @param Request         $request The `login/challenge` request.
     * @return bool
     */
    public function verify(Authenticatable $owner, Request $request): bool;
}
