<?php

namespace GP247\Core\Support\Password;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The password policy of one account group, read from the Password policy screen — the
 * single source every place that sets or stores a password goes through.
 *
 * Values are the site-wide (GLOBAL) `admin_config` rows `<group>_password_*`; a missing row
 * falls back to the group's default. Rows are read straight from the table (not through the
 * request-cached config helpers) so a value saved a moment ago applies at once, and so the
 * class also works before the site is installed (the web installer uses defaults()).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-password-policy-single-source, US-admin-shell-password-policy-audiences
 * @aidlc-adr admin-shell_password-policy-registry
 */
final class PasswordPolicy
{
    /** Lowest minimum the screen accepts — sites upgraded from older versions run at 6. */
    public const MIN_FLOOR = 6;
    /** Highest maximum the screen accepts: bcrypt only uses the first 72 bytes. */
    public const MAX_CEILING = 72;
    /** Defaults of a group nobody registered (should not happen; kept strong rather than open). */
    public const FALLBACK_MIN = 8;
    public const FALLBACK_MAX = 64;

    /** @var list<string> Field suffixes of `<group>_password_<field>`, in screen order. */
    public const FIELDS = ['min', 'max', 'letter', 'mixedcase', 'number', 'symbol'];

    /**
     * @param int                  $min
     * @param int                  $max
     * @param array<string, bool>  $switches letter / mixedcase / number / symbol.
     */
    private function __construct(
        private readonly int $min,
        private readonly int $max,
        private readonly array $switches,
    ) {
    }

    /**
     * Policy of $audience as set on the screen.
     *
     * @param string $audience Group key (admin, customer, ...).
     * @return self
     */
    public static function for(string $audience): self
    {
        $defaults = self::defaults($audience);
        try {
            $stored = DB::connection(GP247_DB_CONNECTION)
                ->table(GP247_DB_PREFIX . 'admin_config')
                ->where('store_id', (string) GP247_STORE_ID_GLOBAL)
                ->whereIn('key', self::keysFor($audience))
                ->pluck('value', 'key')
                ->all();
        } catch (\Illuminate\Database\QueryException|\PDOException $e) {
            // WHY: no database / table yet (before install) — the group's defaults are the policy.
            return $defaults;
        }

        $min = self::positiveInt($stored[self::key($audience, 'min')] ?? null) ?? $defaults->min;
        $max = self::positiveInt($stored[self::key($audience, 'max')] ?? null) ?? $defaults->max;
        // WHY clamp: the screen had no validation before, so an upgraded site may hold values
        // outside what it accepts now; min above max would refuse every password.
        $min = max($min, self::MIN_FLOOR);
        $max = min($max, self::MAX_CEILING);
        if ($min > $max) {
            [$min, $max] = [$defaults->min, $defaults->max];
        }
        $switches = [];
        foreach (['letter', 'mixedcase', 'number', 'symbol'] as $switch) {
            $switches[$switch] = (bool) (int) ($stored[self::key($audience, $switch)] ?? 0);
        }

        return new self($min, $max, $switches);
    }

    /**
     * The group's defaults only (no stored value) — what a fresh install starts with.
     *
     * @param string $audience
     * @return self
     */
    public static function defaults(string $audience): self
    {
        $group = PasswordPolicyRegistry::get($audience) ?? ($audience === 'admin' ? PasswordAudience::admin() : null);

        return new self(
            $group?->defaultMin ?? self::FALLBACK_MIN,
            $group?->defaultMax ?? self::FALLBACK_MAX,
            ['letter' => false, 'mixedcase' => false, 'number' => false, 'symbol' => false],
        );
    }

    /**
     * @return int Minimum length.
     */
    public function min(): int
    {
        return $this->min;
    }

    /**
     * @return int Maximum length.
     */
    public function max(): int
    {
        return $this->max;
    }

    /**
     * Laravel validation rule of this policy (callers add `required` / `nullable`, `string`, `confirmed`).
     *
     * @return Password
     */
    public function rule(): Password
    {
        $rule = Password::min($this->min)->max($this->max)->rules([self::byteLimitRule()]);
        if ($this->switches['letter'] ?? false) {
            $rule = $rule->letters();
        }
        if ($this->switches['mixedcase'] ?? false) {
            $rule = $rule->mixedCase();
        }
        if ($this->switches['number'] ?? false) {
            $rule = $rule->numbers();
        }
        if ($this->switches['symbol'] ?? false) {
            $rule = $rule->symbols();
        }

        return $rule;
    }

    /**
     * Refuse a password longer than bcrypt can hash: the maximum counts characters, and
     * with accented, CJK or emoji characters 64 of them can exceed bcrypt's 72 bytes,
     * which would be silently cut (or refused by the hasher).
     *
     * @return \Closure(string, mixed, \Closure): void
     */
    private static function byteLimitRule(): \Closure
    {
        return static function (string $attribute, $value, \Closure $fail): void {
            if (config('hashing.driver', 'bcrypt') === 'bcrypt' && is_string($value) && strlen($value) > self::MAX_CEILING) {
                $fail(self::text('admin.password_policy.error_bytes', 'The password is too long: it must fit in :ceiling bytes (fewer characters when it has accents or non-Latin letters).', ['ceiling' => self::MAX_CEILING]));
            }
        };
    }

    /**
     * Hash a password to store it — the one hashing call for account passwords
     * (follows config/hashing.php of the site).
     *
     * @param string $plain
     * @return string
     */
    public static function hash(string $plain): string
    {
        return Hash::make($plain);
    }

    /**
     * Config key of one field of a group.
     *
     * @param string $audience
     * @param string $field One of FIELDS.
     * @return string
     */
    public static function key(string $audience, string $field): string
    {
        return $audience . '_password_' . $field;
    }

    /**
     * The six config keys of a group, in screen order.
     *
     * @param string $audience
     * @return list<string>
     */
    public static function keysFor(string $audience): array
    {
        return array_map(fn (string $field) => self::key($audience, $field), self::FIELDS);
    }

    /**
     * Problems of the min / max a site owner is about to save for $audience, keyed by config key.
     * A key absent from $values is not checked (the site has no row for it).
     *
     * @param string               $audience
     * @param array<string, mixed> $values Screen values keyed by config key.
     * @return array<string, string> Message per config key; empty when the values can be saved.
     */
    public static function problems(string $audience, array $values): array
    {
        $minKey = self::key($audience, 'min');
        $maxKey = self::key($audience, 'max');
        $problems = [];
        $min = array_key_exists($minKey, $values) ? self::positiveInt($values[$minKey]) : null;
        $max = array_key_exists($maxKey, $values) ? self::positiveInt($values[$maxKey]) : null;

        if (array_key_exists($minKey, $values) && ($min === null || $min < self::MIN_FLOOR)) {
            $problems[$minKey] = self::text('admin.password_policy.error_min', 'The minimum must be a whole number of at least :floor.', ['floor' => self::MIN_FLOOR]);
        }
        if (array_key_exists($maxKey, $values) && ($max === null || $max > self::MAX_CEILING)) {
            $problems[$maxKey] = self::text('admin.password_policy.error_max', 'The maximum must be a whole number of at most :ceiling.', ['ceiling' => self::MAX_CEILING]);
        }
        if ($problems === [] && $min !== null && $max !== null && $min > $max) {
            $problems[$minKey] = self::text('admin.password_policy.error_range', 'The minimum cannot be greater than the maximum.');
        }

        return $problems;
    }

    /**
     * A positive whole number from a stored / typed value, or null.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function positiveInt($value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return ($int === false || $int < 1) ? null : $int;
    }

    /**
     * Translated text, or the English fallback when the language table has no row yet.
     *
     * @param string                $code
     * @param string                $fallback
     * @param array<string, scalar> $replace
     * @return string
     */
    private static function text(string $code, string $fallback, array $replace = []): string
    {
        if (function_exists('gp247_language_render')) {
            $text = gp247_language_render($code, $replace);
            if (is_string($text) && $text !== '' && $text !== $code) {
                return $text;
            }
        }
        foreach ($replace as $name => $value) {
            $fallback = str_replace(':' . $name, (string) $value, $fallback);
        }

        return $fallback;
    }
}
