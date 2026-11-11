<?php

namespace GP247\Core\Support\Password;

use GP247\Core\Models\AdminUser;
use Illuminate\Support\Facades\Hash;

/**
 * Repairs administrator passwords that an older profile screen hashed AFTER running them
 * through gp247_clean(): the stored hash is of the HTML-encoded, tag-stripped string, so the
 * password the administrator actually typed never matched again.
 *
 * Called on sign-in (web and admin API) before the normal credential check. When the typed
 * password does not match but its old encoded form does, the hash is rewritten from the typed
 * password, so the normal check that follows succeeds and later sign-ins need no repair.
 *
 * The old transform is frozen here on purpose: gp247_clean() is going to change, and this
 * repair must keep recognising hashes written by the old behaviour until it is removed.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-as-typed
 * @aidlc-adr admin-shell_credential-verbatim
 */
final class LegacyEncodedPassword
{
    /**
     * The string the old profile screen hashed for a typed password
     * (gp247_clean() with strip_tags, as it behaved before mod 20261111T224631).
     *
     * @param string $typed Password as typed.
     * @return string
     */
    public static function legacyForm(string $typed): string
    {
        return htmlspecialchars(htmlspecialchars_decode(strip_tags($typed)), ENT_COMPAT, 'UTF-8');
    }

    /**
     * Whether a stored hash belongs to the typed password in either form (as typed, or the
     * old encoded form). Used where an old password must be refused whatever form it has.
     *
     * @param string $typed      Password as typed.
     * @param string|null $hash  Stored hash.
     * @return bool
     */
    public static function matchesEitherForm(string $typed, ?string $hash): bool
    {
        if ($typed === '' || empty($hash)) {
            return false;
        }
        if (Hash::check($typed, $hash)) {
            return true;
        }
        $legacy = self::legacyForm($typed);

        return $legacy !== $typed && Hash::check($legacy, $hash);
    }

    /**
     * Rewrite the hash of an account stored from the old encoded form of the typed password.
     * Does nothing (and costs no extra hash check) when the typed password has no character
     * the old transform changed, or when it already matches. Writes the column directly so
     * no model event runs (this is not a password change: API sessions stay valid) and no
     * authentication event is fired (failed-login log and throttle stay unaware of it).
     *
     * @param AdminUser|null $user  Account resolved from the submitted username.
     * @param string $typed         Password as typed.
     * @return bool Whether the hash was rewritten.
     */
    public static function upgrade(?AdminUser $user, string $typed): bool
    {
        if ($user === null || $typed === '') {
            return false;
        }
        $legacy = self::legacyForm($typed);
        $hash = (string) $user->getAuthPassword();
        if ($legacy === $typed || $hash === '' || Hash::check($typed, $hash) || !Hash::check($legacy, $hash)) {
            return false;
        }

        $user->getConnection()->table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->update(['password' => gp247_password_hash($typed)]);

        return true;
    }
}
