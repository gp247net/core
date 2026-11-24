<?php

namespace GP247\Core\PersonalData\Domain;

use Closure;

/**
 * One table that holds personal data of customers, as declared by the package or extension
 * that owns it. A declaration never carries SQL: the core builds every query from it.
 *
 * Built fluently; every "with" call returns a new instance.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-declare-sources
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class PersonalDataSource
{
    /** @var list<SubjectLink> */
    private array $links = [];
    private RowRule $rowRule = RowRule::Retain;
    /** @var array<string, ColumnRule> */
    private array $columns = [];
    /** @var list<string> */
    private array $identityColumns = [];
    /** @var list<string> */
    private array $emailColumns = [];
    /** @var array<string, string|int> */
    private array $equals = [];
    private ?Closure $filter = null;
    /** @var list<string> */
    private array $export = [];
    private int $order = 50;
    /** @var string|array<string, string> */
    private string|array $label = '';
    private string $owner = '';
    private bool $prefixed = true;

    /**
     * @param string $table Logical table name (without GP247_DB_PREFIX).
     * @param string $key   Single-column primary key.
     */
    private function __construct(public readonly string $table, public readonly string $key)
    {
        SubjectLink::assertIdentifier($table);
        SubjectLink::assertIdentifier($key);
    }

    /**
     * @param string $table
     * @param string $key
     * @return self
     */
    public static function table(string $table, string $key = 'id'): self
    {
        return new self($table, $key);
    }

    /**
     * @param SubjectLink $link Rows matching any link belong to the subject.
     * @return self
     */
    public function link(SubjectLink $link): self
    {
        $copy = clone $this;
        $copy->links[] = $link;

        return $copy;
    }

    /**
     * @return self Rows are deleted.
     */
    public function deleteRows(): self
    {
        $copy = clone $this;
        $copy->rowRule = RowRule::Delete;

        return $copy;
    }

    /**
     * @param array<string, string|array<int|string, mixed>|ColumnRule> $columns Column => rule.
     * @return self Rows are kept; the column rules apply.
     */
    public function anonymise(array $columns): self
    {
        $copy = clone $this;
        $copy->rowRule = RowRule::Anonymise;
        foreach ($columns as $column => $rule) {
            SubjectLink::assertIdentifier((string) $column);
            $copy->columns[(string) $column] = $rule instanceof ColumnRule ? $rule : ColumnRule::parse($rule);
        }

        return $copy;
    }

    /**
     * @return self Rows are kept untouched (retention removes them later).
     */
    public function retain(): self
    {
        $copy = clone $this;
        $copy->rowRule = RowRule::Retain;

        return $copy;
    }

    /**
     * @param list<string> $columns Values that identify the person (names, phone, address lines…),
     *                              collected before erasing to scrub free text elsewhere.
     * @param list<string> $emails  Email columns whose values extend the subject (guest orders).
     * @return self
     */
    public function identity(array $columns, array $emails = []): self
    {
        $copy = clone $this;
        foreach (array_merge($columns, $emails) as $column) {
            SubjectLink::assertIdentifier($column);
        }
        $copy->identityColumns = array_values(array_unique(array_merge($copy->identityColumns, $columns)));
        $copy->emailColumns = array_values(array_unique(array_merge($copy->emailColumns, $emails)));

        return $copy;
    }

    /**
     * @param array<string, string|int> $equals Fixed conditions (column => value).
     * @return self
     */
    public function where(array $equals): self
    {
        $copy = clone $this;
        foreach ($equals as $column => $value) {
            SubjectLink::assertIdentifier((string) $column);
            $copy->equals[(string) $column] = $value;
        }

        return $copy;
    }

    /**
     * @param Closure(\Illuminate\Database\Query\Builder): void $filter Extra condition (code declarations only).
     * @return self
     */
    public function whereUsing(Closure $filter): self
    {
        $copy = clone $this;
        $copy->filter = $filter;

        return $copy;
    }

    /**
     * @param list<string> $columns Columns placed in the customer's data export.
     * @return self
     */
    public function export(array $columns): self
    {
        $copy = clone $this;
        foreach ($columns as $column) {
            SubjectLink::assertIdentifier($column);
        }
        $copy->export = array_values($columns);

        return $copy;
    }

    /**
     * @param int $order Processing order (lower first: children before the rows they point to).
     * @return self
     */
    public function order(int $order): self
    {
        $copy = clone $this;
        $copy->order = $order;

        return $copy;
    }

    /**
     * @param string|array<string, string> $label Language key, or texts by locale.
     * @param string                       $owner Package or extension key.
     * @return self
     */
    public function describe(string|array $label, string $owner): self
    {
        $copy = clone $this;
        $copy->label = $label;
        $copy->owner = $owner;

        return $copy;
    }

    /**
     * @return self The table is named without GP247_DB_PREFIX (a framework table such as
     *              Sanctum's personal_access_tokens).
     */
    public function unprefixed(): self
    {
        $copy = clone $this;
        $copy->prefixed = false;

        return $copy;
    }

    /**
     * @return string Table name in the database.
     */
    public function physicalTable(): string
    {
        return ($this->prefixed ? GP247_DB_PREFIX : '') . $this->table;
    }

    /**
     * @return list<SubjectLink>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * @return RowRule
     */
    public function rowRule(): RowRule
    {
        return $this->rowRule;
    }

    /**
     * @return array<string, ColumnRule>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<string>
     */
    public function identityColumns(): array
    {
        return $this->identityColumns;
    }

    /**
     * @return list<string>
     */
    public function emailColumns(): array
    {
        return $this->emailColumns;
    }

    /**
     * @return array<string, string|int>
     */
    public function equals(): array
    {
        return $this->equals;
    }

    /**
     * @return Closure|null
     */
    public function filter(): ?Closure
    {
        return $this->filter;
    }

    /**
     * @return list<string>
     */
    public function exportColumns(): array
    {
        return $this->export;
    }

    /**
     * @return int
     */
    public function sortOrder(): int
    {
        return $this->order;
    }

    /**
     * @return string|array<string, string>
     */
    public function label(): string|array
    {
        return $this->label;
    }

    /**
     * @return string
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * @return void
     * @throws \InvalidArgumentException When the declaration cannot be applied safely.
     */
    public function assertComplete(): void
    {
        if ($this->links === []) {
            throw new \InvalidArgumentException('Personal data source ' . $this->table . ' has no link to a customer');
        }
        if ($this->rowRule === RowRule::Anonymise) {
            $changing = array_filter($this->columns, fn (ColumnRule $r) => $r->kind !== ColumnRule::KEEP);
            if ($changing === []) {
                throw new \InvalidArgumentException('Personal data source ' . $this->table . ' anonymises no column');
            }
        }
    }
}
