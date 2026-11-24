<?php

namespace GP247\Core\PersonalData\Application;

use GP247\Core\PersonalData\Domain\ErasureBlocker;
use GP247\Core\PersonalData\Domain\PersonalDataSource;

/**
 * Every table holding customers' personal data, declared by the package or extension that owns
 * it, and every reason a package may have to postpone an erasure. Packages register in their
 * service provider's boot(); extensions through the "personalData" key of gp247.json (read
 * lazily, enabled or not — a disabled plugin's data must still be erasable).
 *
 * A later code declaration of the same table replaces the earlier one (registry convention);
 * a manifest never replaces a code declaration.
 *
 * @aidlc-unit personal-data-registry
 * @aidlc-story US-personal-data-registry-declare-sources
 * @aidlc-adr personal-data-registry_source-declaration
 */
final class PersonalDataRegistry
{
    /** @var array<string, PersonalDataSource> */
    private static array $sources = [];
    /** @var array<string, ErasureBlocker> */
    private static array $blockers = [];
    /** @var array<string, PersonalDataSource>|null */
    private static ?array $manifestSources = null;
    /** @var array<string, string> Table => why it holds no customer data. */
    private static array $notCustomerData = [];
    /** @var (\Closure(string): (array{id: string, email: string|null, name: string|null, store_id: string|null}|null))|null */
    private static ?\Closure $customerResolver = null;

    /**
     * @param PersonalDataSource $source
     * @return void
     * @throws \InvalidArgumentException When the declaration is incomplete (shown to the developer).
     */
    public static function declare(PersonalDataSource $source): void
    {
        $source->assertComplete();
        // WHY no warning on a second declaration: providers boot again for every application
        // instance in one PHP process (tests, Octane); the later declaration simply wins.
        self::$sources[$source->table] = $source;
    }

    /**
     * @param ErasureBlocker $blocker
     * @return void
     */
    public static function blocker(ErasureBlocker $blocker): void
    {
        self::$blockers[$blocker::class] = $blocker;
    }

    /**
     * @return list<PersonalDataSource> Code and manifest declarations, in processing order.
     */
    public static function sources(): array
    {
        $all = self::$sources + self::manifestSources();
        $list = array_values($all);
        usort($list, fn (PersonalDataSource $a, PersonalDataSource $b) => [$a->sortOrder(), $a->table] <=> [$b->sortOrder(), $b->table]);

        return $list;
    }

    /**
     * @param string $table
     * @return PersonalDataSource|null
     */
    public static function source(string $table): ?PersonalDataSource
    {
        return self::$sources[$table] ?? self::manifestSources()[$table] ?? null;
    }

    /**
     * A table of the package that looks like personal data by its column names but holds no
     * customer data (administrator accounts, the shop's own address…). Undeclared-source
     * detection skips it.
     *
     * @param string $table  Logical table name.
     * @param string $reason Short reason, for developers.
     * @return void
     */
    public static function notCustomerData(string $table, string $reason): void
    {
        self::$notCustomerData[$table] = $reason;
    }

    /**
     * @return array<string, string> Table => reason.
     */
    public static function notCustomerTables(): array
    {
        return self::$notCustomerData;
    }

    /**
     * The package owning the customer model (gp247/shop) tells the core how to find a customer
     * by id or email — the core has no customer table of its own.
     *
     * @param \Closure(string): (array{id: string, email: string|null, name: string|null, store_id: string|null}|null) $resolver
     * @return void
     */
    public static function customerResolver(\Closure $resolver): void
    {
        self::$customerResolver = $resolver;
    }

    /**
     * @param string $idOrEmail
     * @return array{id: string, email: string|null, name: string|null, store_id: string|null}|null The customer, or null
     *         when none matches or no package resolves customers.
     */
    public static function findCustomer(string $idOrEmail): ?array
    {
        $value = trim($idOrEmail);
        if ($value === '' || self::$customerResolver === null) {
            return null;
        }
        $found = (self::$customerResolver)($value);

        return is_array($found) && isset($found['id']) ? $found : null;
    }

    /**
     * @return list<ErasureBlocker>
     */
    public static function blockers(): array
    {
        return array_values(self::$blockers);
    }

    /**
     * @return void Forget every declaration (tests, re-boot).
     */
    public static function reset(): void
    {
        self::$sources = [];
        self::$blockers = [];
        self::$manifestSources = null;
        self::$notCustomerData = [];
        self::$customerResolver = null;
    }

    /**
     * @param array<string, PersonalDataSource>|null $sources Replace the manifest declarations (tests); null = read again.
     * @return void
     */
    public static function useManifestSources(?array $sources): void
    {
        self::$manifestSources = $sources;
    }

    /**
     * @return array<string, PersonalDataSource>
     */
    private static function manifestSources(): array
    {
        if (self::$manifestSources === null) {
            self::$manifestSources = ManifestDeclarations::read();
        }

        return self::$manifestSources;
    }
}
