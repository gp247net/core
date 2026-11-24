<?php

namespace GP247\Core\PersonalData\Domain;

/**
 * Values that identify one person (names, emails, phone numbers, address lines, postcodes,
 * company), gathered before anything is erased, so the same values can be removed from free
 * text kept elsewhere (order history, notes). Each value also matches its HTML-escaped form,
 * because some histories store escaped text. Values shorter than 3 characters are ignored:
 * replacing "Le" or "12" would damage unrelated text.
 *
 * Lives only inside the encrypted cursor of an erasure job and is dropped when the job ends.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-free-text-scrub
 * @aidlc-adr personal-data-registry_anonymisation-rules
 */
final class KnownValues
{
    public const MIN_LENGTH = 3;
    /** A value made of digits only (house number, short code) is too common below this many digits. */
    public const MIN_DIGITS = 6;

    /** @var list<string> */
    private array $values;

    /**
     * @param list<string> $values
     */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    /**
     * @param iterable<mixed> $values
     * @return self
     */
    public static function of(iterable $values): self
    {
        $out = [];
        foreach ($values as $value) {
            foreach (self::variants($value) as $variant) {
                $out[$variant] = true;
            }
        }
        $list = array_map('strval', array_keys($out));
        // WHY longest first: "12 Nguyen Trai Street" must be replaced before "Nguyen Trai".
        usort($list, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));

        return new self($list);
    }

    /**
     * @param self $other
     * @return self
     */
    public function merge(self $other): self
    {
        return self::of(array_merge($this->values, $other->values));
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * @param string $text
     * @param string $replacement
     * @return string The text with every known value (case-insensitive) replaced.
     */
    public function scrub(string $text, string $replacement = ColumnRule::MARKER): string
    {
        foreach ($this->values as $value) {
            if (preg_match('/^[\d\s+().-]+$/', $value) === 1) {
                // WHY: a numeric value (postcode, phone) must not eat part of a longer number —
                // "70000" inside the amount "1700000" of a history line stays untouched.
                $text = (string) preg_replace('/(?<!\d)' . preg_quote($value, '/') . '(?!\d)/u', $replacement, $text);
                continue;
            }
            // WHY /iu: str_ireplace folds ASCII only, so "ĐỨC" would not match "Đức".
            $text = (string) preg_replace_callback('/' . preg_quote($value, '/') . '/iu', fn () => $replacement, $text);
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @return list<string> The value as typed, HTML-escaped, and (phones) digits only.
     */
    private static function variants(mixed $value): array
    {
        if (!is_scalar($value)) {
            return [];
        }
        $text = trim((string) $value);
        if (mb_strlen($text) < self::MIN_LENGTH || $text === ColumnRule::MARKER) {
            return [];
        }
        if (preg_match('/^[\d\s+().-]+$/', $text) === 1 && strlen((string) preg_replace('/\D+/', '', $text)) < self::MIN_DIGITS) {
            // WHY: "100" as an address line would replace amounts and quantities elsewhere.
            return [];
        }
        $variants = [$text, htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), htmlspecialchars($text, ENT_QUOTES, 'UTF-8')];
        $digits = preg_replace('/\D+/', '', $text);
        // WHY: a phone typed "+84 912 345 678" is often stored elsewhere as "84912345678".
        if (is_string($digits) && strlen($digits) >= 6 && strlen($digits) >= (int) floor(mb_strlen($text) * 0.6)) {
            $variants[] = $digits;
        }

        return array_values(array_unique(array_filter($variants, fn ($v) => mb_strlen($v) >= self::MIN_LENGTH)));
    }
}
