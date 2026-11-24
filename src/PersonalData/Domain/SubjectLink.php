<?php

namespace GP247\Core\PersonalData\Domain;

/**
 * How the rows of one source belong to a data subject.
 *
 *  - customer: a column holds the customer id;
 *  - email:    a column holds an email address (optionally only on rows where another
 *              column says "no account", e.g. guest orders with customer_id 0);
 *  - morph:    a polymorphic pair (type column = a fixed value, id column = customer id);
 *  - parent:   a column holds the key of a row of another declared source (optionally only
 *              when a type column matches, e.g. payment_request.subject_type).
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-declare-sources
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class SubjectLink
{
    public const CUSTOMER = 'customer';
    public const EMAIL = 'email';
    public const MORPH = 'morph';
    public const PARENT = 'parent';

    /**
     * @param string      $kind         One of the constants.
     * @param string      $column       Column of this table holding the id / email / parent key.
     * @param string|null $typeColumn   Morph type column, or the parent type column.
     * @param string|null $typeValue    Value the type column must have.
     * @param string|null $parentTable  Declared source table (parent link).
     * @param string|null $guestColumn  Email link: only rows whose column is empty / 0 / NULL.
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $column,
        public readonly ?string $typeColumn = null,
        public readonly ?string $typeValue = null,
        public readonly ?string $parentTable = null,
        public readonly ?string $guestColumn = null,
    ) {
        self::assertIdentifier($column);
        foreach ([$typeColumn, $parentTable, $guestColumn] as $name) {
            if ($name !== null) {
                self::assertIdentifier($name);
            }
        }
    }

    /**
     * @param string $column
     * @return self
     */
    public static function customer(string $column): self
    {
        return new self(self::CUSTOMER, $column);
    }

    /**
     * @param string      $column
     * @param string|null $onlyWhenEmpty Restrict to rows where this column is empty, 0 or NULL.
     * @return self
     */
    public static function email(string $column, ?string $onlyWhenEmpty = null): self
    {
        return new self(self::EMAIL, $column, guestColumn: $onlyWhenEmpty);
    }

    /**
     * @param string $typeColumn
     * @param string $idColumn
     * @param string $typeValue
     * @return self
     */
    public static function morph(string $typeColumn, string $idColumn, string $typeValue): self
    {
        return new self(self::MORPH, $idColumn, $typeColumn, $typeValue);
    }

    /**
     * @param string      $parentTable Declared source (logical table name).
     * @param string      $column      Column here holding the parent key.
     * @param string|null $typeColumn  Column here that must equal $typeValue.
     * @param string|null $typeValue
     * @return self
     */
    public static function parent(string $parentTable, string $column, ?string $typeColumn = null, ?string $typeValue = null): self
    {
        return new self(self::PARENT, $column, $typeColumn, $typeValue, $parentTable);
    }

    /**
     * @param array<string, mixed> $data Manifest form: {"customer": col} | {"email": col, "guest": col} | {"parent": table, "column": col}.
     * @return self
     * @throws \InvalidArgumentException When the form is unknown.
     */
    public static function fromArray(array $data): self
    {
        return match (true) {
            isset($data['customer']) => self::customer((string) $data['customer']),
            isset($data['email']) => self::email((string) $data['email'], isset($data['guest']) ? (string) $data['guest'] : null),
            isset($data['parent'], $data['column']) => self::parent(
                (string) $data['parent'],
                (string) $data['column'],
                isset($data['type_column']) ? (string) $data['type_column'] : null,
                isset($data['type_value']) ? (string) $data['type_value'] : null,
            ),
            default => throw new \InvalidArgumentException('Unknown personal data link'),
        };
    }

    /**
     * @param string $name
     * @return void
     * @throws \InvalidArgumentException When the name is not a plain SQL identifier.
     */
    public static function assertIdentifier(string $name): void
    {
        // WHY: names come from manifests of third-party extensions and end up as identifiers
        // in queries — identifiers cannot be bound, so only plain names are accepted.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid identifier in personal data declaration: ' . $name);
        }
    }
}
