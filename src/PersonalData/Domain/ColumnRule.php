<?php

namespace GP247\Core\PersonalData\Domain;

/**
 * What happens to one column of a kept ("anonymise") row when its data subject is erased.
 *
 *  - keep:      unchanged (amounts, ids, dates, statuses);
 *  - null:      emptied (NULL, or '' when the column does not allow NULL);
 *  - mark:      replaced by the neutral marker "[deleted]" (required name columns);
 *  - scrubText: the subject's known values inside free text are replaced by the marker;
 *  - scrubJson: the listed keys (dot paths) of a JSON document are emptied.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-erase-anonymise, US-personal-data-registry-free-text-scrub
 * @aidlc-adr personal-data-registry_anonymisation-rules
 */
final class ColumnRule
{
    public const KEEP = 'keep';
    public const NULL = 'null';
    public const MARK = 'mark';
    public const SCRUB_TEXT = 'scrubText';
    public const SCRUB_JSON = 'scrubJson';

    /** Neutral marker written in place of erased names; admin screens translate it. */
    public const MARKER = '[deleted]';

    private const KINDS = [self::KEEP, self::NULL, self::MARK, self::SCRUB_TEXT, self::SCRUB_JSON];

    /**
     * @param string       $kind
     * @param list<string> $keys Dot paths for scrubJson.
     */
    private function __construct(public readonly string $kind, public readonly array $keys = [])
    {
    }

    /**
     * @param string       $kind
     * @param list<string> $keys
     * @return self
     * @throws \InvalidArgumentException When the kind is unknown or scrubJson has no key.
     */
    public static function of(string $kind, array $keys = []): self
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown column rule: ' . $kind);
        }
        if ($kind === self::SCRUB_JSON && $keys === []) {
            throw new \InvalidArgumentException('scrubJson needs at least one key');
        }

        return new self($kind, array_values(array_map('strval', $keys)));
    }

    /**
     * @param string|array<int|string, mixed> $spec "null", "mark", … or ["scrubJson", ["a.b", "note"]].
     * @return self
     */
    public static function parse(string|array $spec): self
    {
        if (is_string($spec)) {
            return self::of($spec);
        }
        $spec = array_values($spec);

        return self::of((string) ($spec[0] ?? ''), (array) ($spec[1] ?? []));
    }

    /**
     * @return bool Whether the rule writes the same value to every row (bulk update possible).
     */
    public function isConstant(): bool
    {
        return $this->kind === self::NULL || $this->kind === self::MARK;
    }
}
