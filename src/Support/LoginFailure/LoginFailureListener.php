<?php

namespace GP247\Core\Support\LoginFailure;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Str;

/**
 * Hears every failed credential check of every auth guard — admin form and API,
 * storefront form and API, plugin guards — so no controller has to call the log.
 * Only the identity field of the credentials is read; the password never is.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-login-failure-log
 * @aidlc-adr admin-shell_login-failure-log
 */
class LoginFailureListener
{
    /** Credential keys that name the account, in the order they are tried. */
    private const IDENTITY_KEYS = ['username', 'email', 'login', 'phone'];

    /**
     * @param Failed $event
     * @return void
     */
    public function failed(Failed $event): void
    {
        $credentials = is_array($event->credentials) ? $event->credentials : [];
        $identity = null;
        foreach (self::IDENTITY_KEYS as $key) {
            if (isset($credentials[$key]) && is_scalar($credentials[$key])) {
                $identity = (string) $credentials[$key];
                break;
            }
        }
        $accountId = $event->user !== null ? $event->user->getAuthIdentifier() : null;

        app(LoginFailureRecorder::class)->failed((string) $event->guard, $identity, $accountId);
    }

    /**
     * Laravel's own limiter (storefront sign-in form) refused a sign-in.
     *
     * WHY the path decides the group: the event carries no guard; the admin form uses
     * GP247's own limiter, so a lockout outside the admin path is a customer form.
     *
     * @param Lockout $event
     * @return void
     */
    public function lockout(Lockout $event): void
    {
        $request = $event->request;
        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';
        $audience = $request->is($prefix . '/*') ? 'admin' : 'customer';
        $identity = null;
        foreach (self::IDENTITY_KEYS as $key) {
            $value = $request->input($key);
            if (is_scalar($value) && (string) $value !== '') {
                $identity = (string) $value;
                break;
            }
        }
        $window = 'lockout|' . $audience . '|' . Str::lower((string) $identity) . '|' . $request->ip();

        app(LoginFailureRecorder::class)->throttled($audience, $identity, null, $window, 60, $request);
    }
}
