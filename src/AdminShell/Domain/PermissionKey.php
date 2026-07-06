<?php

namespace GP247\Core\AdminShell\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * Immutable value object for a permission slug (e.g. "admin_product").
 *
 * Wrapping the raw string guarantees a non-empty, trimmed identifier flows
 * through the authorization core, so callers cannot accidentally authorize
 * against a blank key.
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-001
 * @aidlc-adr ADR-001
 */
final class PermissionKey implements Stringable
{
    private readonly string $value;

    /**
     * @param string $value Permission slug; surrounding whitespace is trimmed.
     * @throws InvalidArgumentException When the value is empty after trimming.
     */
    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Permission key must not be empty.');
        }

        $this->value = $trimmed;
    }

    /**
     * @return string The normalized permission slug.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * @param PermissionKey $other Key to compare against.
     * @return bool True when both keys hold the same slug.
     */
    public function equals(PermissionKey $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
