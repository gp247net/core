<?php

namespace GP247\Core\PersonalData\Domain\Retention;

use Carbon\CarbonImmutable;
use Closure;
use GP247\Core\PersonalData\Domain\SubjectLink;

/**
 * One kind of data the site keeps only for a while, declared by the package or extension that
 * owns it: which table, from which date column the age counts, which rows (fixed conditions),
 * what happens when they are too old, and the days kept by default and within which range.
 * The site owner turns it on or off and sets the days; the declaration never carries SQL.
 *
 * Row items name a declared personal data source whose rule deletes rows, or a table declared as
 * holding no customer data (an administrators' log). Record items name a declared source whose
 * records are anonymised by the erasure engine. Account items find accounts unused for the period.
 *
 * Built fluently; every call returns a new instance.
 *
 * @aidlc-unit personal-data-retention
 * @aidlc-story US-personal-data-retention-policies
 * @aidlc-adr personal-data-retention_retention-registry
 */
final class RetentionItem
{
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)?$/';
    /** WHY 29: admin_config keys are at most 50 characters ("pd_retention_" + key + "_enabled"). */
    private const KEY_MAX = 29;

    private string $ageColumn = 'created_at';
    /** @var array<string, string|int> */
    private array $equals = [];
    private ?Closure $filter = null;
    private int $defaultDays = 365;
    private int $minDays = 1;
    private int $maxDays = 3650;
    private bool $enabledByDefault = true;
    private bool $risky = false;
    /** @var list<string> */
    private array $backupGroups = [];
    private ?Closure $expiry = null;
    private ?Closure $finder = null;
    private ?Closure $activity = null;
    private ?Closure $counter = null;
    /** @var string|array<string, string> */
    private string|array $label = '';
    private string $owner = '';
    private int $order = 50;

    /**
     * @param string          $key    Unique key, e.g. "shop.carts".
     * @param RetentionAction $action
     * @param string|null     $table  Logical table name (none for account items).
     * @throws \InvalidArgumentException When the key or the table is not a plain name.
     */
    private function __construct(public readonly string $key, public readonly RetentionAction $action, public readonly ?string $table)
    {
        // WHY: the key becomes part of configuration keys and of the state table's primary key.
        if (strlen($key) > self::KEY_MAX || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException('Invalid retention item key: ' . $key);
        }
        if ($table !== null) {
            SubjectLink::assertIdentifier($table);
        }
    }

    /**
     * @param string $key
     * @param string $table Declared source that deletes rows, or a table declared as holding no customer data.
     * @return self Rows older than the period are deleted.
     */
    public static function rows(string $key, string $table): self
    {
        return new self($key, RetentionAction::Delete, $table);
    }

    /**
     * @param string $key
     * @param string $table Declared source whose records are anonymised (with the sources under it).
     * @return self Records older than the period are anonymised through the erasure engine.
     */
    public static function records(string $key, string $table): self
    {
        return new self($key, RetentionAction::Anonymise, $table);
    }

    /**
     * @param string $key
     * @return self Accounts unused for the period are told, then erased like a customer's own request.
     */
    public static function accounts(string $key): self
    {
        return (new self($key, RetentionAction::EraseAccounts, null))->disabledByDefault();
    }

    /**
     * @param string $column Date column the age counts from.
     * @return self
     */
    public function olderThan(string $column): self
    {
        SubjectLink::assertIdentifier($column);
        $copy = clone $this;
        $copy->ageColumn = $column;

        return $copy;
    }

    /**
     * @param array<string, string|int> $equals Fixed conditions (column => value).
     * @return self
     */
    public function where(array $equals): self
    {
        foreach (array_keys($equals) as $column) {
            SubjectLink::assertIdentifier((string) $column);
        }
        $copy = clone $this;
        $copy->equals = $equals + $copy->equals;

        return $copy;
    }

    /**
     * @param Closure(\Illuminate\Database\Query\Builder): void $filter Fixed condition written by the package (bound values only).
     * @return self
     */
    public function whereUsing(Closure $filter): self
    {
        $copy = clone $this;
        $copy->filter = $filter;

        return $copy;
    }

    /**
     * @param int $default
     * @param int $min
     * @param int $max
     * @return self
     * @throws \InvalidArgumentException When the range is empty or the default is outside it.
     */
    public function days(int $default, int $min, int $max): self
    {
        if ($min < 1 || $max < $min || $default < $min || $default > $max) {
            throw new \InvalidArgumentException('Invalid retention days for ' . $this->key);
        }
        $copy = clone $this;
        $copy->defaultDays = $default;
        $copy->minDays = $min;
        $copy->maxDays = $max;

        return $copy;
    }

    /**
     * @param Closure(): int $seconds How long a row stays valid (e.g. a reset code's expiry); the days are then not set by the owner.
     * @return self
     */
    public function expiresAfter(Closure $seconds): self
    {
        $copy = clone $this;
        $copy->expiry = $seconds;

        return $copy;
    }

    /**
     * @return self Off until the site owner turns it on.
     */
    public function disabledByDefault(): self
    {
        $copy = clone $this;
        $copy->enabledByDefault = false;

        return $copy;
    }

    /**
     * @param list<string> $backupGroups Backup data groups saved before the item is turned on or its period shortened.
     * @return self Removes business data: turning it on shows how many records are concerned and asks for the password again.
     */
    public function risky(array $backupGroups = []): self
    {
        $copy = clone $this;
        $copy->risky = true;
        $copy->backupGroups = array_values(array_filter($backupGroups, 'is_string'));

        return $copy;
    }

    /**
     * @param Closure(\DateTimeInterface, string|null, int): list<array{id: string, store_id?: string|null}> $finder
     *        Accounts with no activity since the date, ordered by id, after the given id, at most the given number.
     * @return self
     */
    public function findAccountsWith(Closure $finder): self
    {
        $copy = clone $this;
        $copy->finder = $finder;

        return $copy;
    }

    /**
     * @param Closure(string, \DateTimeInterface): bool $activity Whether the account was used (signed in, ordered, used the app) since the date.
     * @return self Checked again before an unused account is erased: activity during the notice keeps it.
     */
    public function checkActivityWith(Closure $activity): self
    {
        $copy = clone $this;
        $copy->activity = $activity;

        return $copy;
    }

    /**
     * @param Closure(\DateTimeInterface): int $counter Accounts with no activity since the date (preview).
     * @return self
     */
    public function countAccountsWith(Closure $counter): self
    {
        $copy = clone $this;
        $copy->counter = $counter;

        return $copy;
    }

    /**
     * @param string|array<string, string> $label Language key, or texts by locale.
     * @param string                       $owner Package or extension key.
     * @param int                          $order Place on the screen.
     * @return self
     */
    public function describe(string|array $label, string $owner, int $order = 50): self
    {
        $copy = clone $this;
        $copy->label = $label;
        $copy->owner = $owner;
        $copy->order = $order;

        return $copy;
    }

    /**
     * @param mixed $value Days as typed by the owner.
     * @return int Kept within the allowed range; not a number ⇒ the default.
     */
    public function clampDays(mixed $value): int
    {
        $days = is_numeric($value) ? (int) $value : $this->defaultDays;

        return max($this->minDays, min($this->maxDays, $days));
    }

    /**
     * @param int             $days
     * @param CarbonImmutable $now
     * @return CarbonImmutable Rows dated before this moment are due.
     */
    public function cutoff(int $days, CarbonImmutable $now): CarbonImmutable
    {
        if ($this->expiry !== null) {
            return $now->subSeconds(max(0, (int) ($this->expiry)()));
        }

        return $now->subDays($this->clampDays($days));
    }

    /**
     * @return void
     * @throws \InvalidArgumentException When the declaration cannot be run safely.
     */
    public function assertComplete(): void
    {
        if ($this->action === RetentionAction::EraseAccounts && $this->finder === null) {
            throw new \InvalidArgumentException('Retention item ' . $this->key . ' has no way to find inactive accounts');
        }
        if ($this->action !== RetentionAction::EraseAccounts && $this->table === null) {
            throw new \InvalidArgumentException('Retention item ' . $this->key . ' names no table');
        }
    }

    /**
     * @return string admin_config key of the on / off switch.
     */
    public function enabledKey(): string
    {
        return $this->configPrefix() . '_enabled';
    }

    /**
     * @return string admin_config key of the days kept.
     */
    public function daysKey(): string
    {
        return $this->configPrefix() . '_days';
    }

    /**
     * @return string
     */
    private function configPrefix(): string
    {
        return 'pd_retention_' . str_replace('.', '_', $this->key);
    }

    /**
     * @return string
     */
    public function ageColumn(): string
    {
        return $this->ageColumn;
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
     * @return int
     */
    public function defaultDays(): int
    {
        return $this->defaultDays;
    }

    /**
     * @return int
     */
    public function minDays(): int
    {
        return $this->minDays;
    }

    /**
     * @return int
     */
    public function maxDays(): int
    {
        return $this->maxDays;
    }

    /**
     * @return bool
     */
    public function enabledByDefault(): bool
    {
        return $this->enabledByDefault;
    }

    /**
     * @return bool
     */
    public function isRisky(): bool
    {
        return $this->risky;
    }

    /**
     * @return list<string>
     */
    public function backupGroups(): array
    {
        return $this->backupGroups;
    }

    /**
     * @return bool Whether the age comes from the row's own expiry rather than days set by the owner.
     */
    public function hasFixedExpiry(): bool
    {
        return $this->expiry !== null;
    }

    /**
     * @return Closure|null
     */
    public function accountFinder(): ?Closure
    {
        return $this->finder;
    }

    /**
     * @return Closure|null
     */
    public function activityCheck(): ?Closure
    {
        return $this->activity;
    }

    /**
     * @return Closure|null
     */
    public function accountCounter(): ?Closure
    {
        return $this->counter;
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
     * @return int
     */
    public function sortOrder(): int
    {
        return $this->order;
    }
}
