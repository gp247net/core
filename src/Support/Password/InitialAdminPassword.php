<?php

namespace GP247\Core\Support\Password;

use GP247\Core\Database\Seeders\DataDefaultSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Password of the administrator account a command-line install seeds, so no install
 * leaves the publicly known default behind (US-CLI-003).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr admin-shell_password-policy-registry
 */
final class InitialAdminPassword
{
    public const LENGTH = 20;

    /**
     * Why $plain cannot be the administrator password, or null when it can. The message
     * never contains the password itself (it may end up in a terminal log).
     *
     * @param string $plain
     * @return string|null
     */
    public static function problem(string $plain): ?string
    {
        $policy = PasswordPolicy::defaults('admin');

        return self::meets($policy, $plain) ? null : 'The administrator password must be ' . $policy->min() . ' to ' . $policy->max() . ' characters long.';
    }

    /**
     * A random password that meets the administrator default policy (letters and digits,
     * no symbols, so it can be copied from a terminal without quoting).
     *
     * @return string
     */
    public static function generate(): string
    {
        return self::generateFor(PasswordPolicy::defaults('admin'));
    }

    /**
     * A random password that meets $policy — letters and digits first (easy to copy from a
     * terminal), adding symbols only when the policy demands them.
     *
     * @param PasswordPolicy $policy
     * @return string
     * @throws \RuntimeException When no candidate meets the policy (not expected for a valid policy).
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-admin-password-reset
     */
    public static function generateFor(PasswordPolicy $policy): string
    {
        $length = max($policy->min(), min(self::LENGTH, $policy->max()));
        foreach ([false, true] as $symbols) {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $password = Str::password($length, true, true, $symbols);
                if (self::meets($policy, $password)) {
                    return $password;
                }
            }
        }

        throw new \RuntimeException('Could not generate a password that meets the administrator password policy.');
    }

    /**
     * Whether $plain passes $policy.
     *
     * @param PasswordPolicy $policy
     * @param string $plain
     * @return bool
     */
    public static function meets(PasswordPolicy $policy, string $plain): bool
    {
        return Validator::make(['password' => $plain], ['password' => ['required', 'string', $policy->rule()]])->passes();
    }

    /**
     * Give the seeded administrator $plain — only while it still has the seed password, so
     * re-running an install on a site whose owner already changed it overwrites nothing.
     *
     * @param string $plain
     * @return bool True when the password was set.
     */
    public static function assign(string $plain): bool
    {
        return DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'admin_user')
            ->where('id', DataDefaultSeeder::SEED_ADMIN_ID)
            ->where('password', (new DataDefaultSeeder)->adminPassword)
            ->update(['password' => PasswordPolicy::hash($plain)]) > 0;
    }

    /**
     * Username of the seeded administrator (shown with the password at the end of an install).
     *
     * @return string
     */
    public static function username(): string
    {
        return (string) (DB::connection(GP247_DB_CONNECTION)
            ->table(GP247_DB_PREFIX . 'admin_user')
            ->where('id', DataDefaultSeeder::SEED_ADMIN_ID)
            ->value('username') ?? 'admin');
    }
}
