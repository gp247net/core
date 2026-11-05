<?php

namespace GP247\Core\Support\Password;

/**
 * One group of accounts that sets its own password (administrators, customers, vendors…),
 * with the defaults its policy starts from on a fresh install.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-policy-audiences
 * @aidlc-adr admin-shell_password-policy-registry
 */
final class PasswordAudience
{
    /** Administrator defaults on a fresh install: PCI DSS 4.0 (8.3.6) asks 12+ for admin accounts. */
    public const ADMIN_MIN = 12;
    public const ADMIN_MAX = 64;

    /**
     * @param string $key        Group key; also the config-key prefix (`<key>_password_*`) and the screen block id.
     * @param string $labelKey   Language code of the block title.
     * @param int    $defaultMin Minimum length used when the site has no stored value.
     * @param int    $defaultMax Maximum length used when the site has no stored value.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly int $defaultMin,
        public readonly int $defaultMax,
    ) {
    }

    /**
     * The administrator group core registers for itself.
     *
     * @return self
     */
    public static function admin(): self
    {
        return new self('admin', 'admin.password_policy.audience_admin', self::ADMIN_MIN, self::ADMIN_MAX);
    }
}
