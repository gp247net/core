<?php

namespace GP247\Core\Api\Support;

/**
 * Sanctum ability middleware strings shared by every admin / customer API route file,
 * so v1, v2 and plugin routes accept exactly the same token types.
 *
 * Abilities only tell which kind of account owns the token; what an admin may read is
 * decided by RBAC (api.admin). "admin-super" is accepted next to the legacy
 * "admin-supper" that tokens issued so far carry.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-config-naming, US-RBAC-004
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
final class ApiAbilities
{
    /**
     * `ability:` middleware for admin tokens.
     *
     * @return string
     */
    public static function admin(): string
    {
        return 'ability:' . implode(',', array_unique(array_filter([
            config('gp247-config.api.auth.api_scope_admin'),
            config('gp247-config.api.auth.api_scope_admin_super'),
            'admin-supper',
            'admin-super',
        ])));
    }

    /**
     * `ability:` middleware for customer tokens.
     *
     * @return string
     */
    public static function customer(): string
    {
        return 'ability:' . config('gp247-config.api.auth.api_scope_user', 'user');
    }
}
