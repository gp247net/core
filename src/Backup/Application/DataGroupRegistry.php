<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\DataGroup;
use Illuminate\Support\Facades\Schema;

/**
 * Business data groups for selective backup and restore.
 *
 * Sources, in claim order: groups declared in `gp247-config.backup.data_groups` (the core's own
 * tables, then whatever front / shop appended through {@see declare()}), then the "dataTables" key of
 * each extension's gp247.json. Only existing tables are kept; a table claimed twice stays with the
 * first group and is reported by {@see duplicates()}; prefixed tables nobody claims form "Other"
 * ({@see unassigned()}). The core never names front / shop tables (NFR-MAINT-001).
 *
 * Tables without GP247_DB_PREFIX (Laravel's `migrations`, older plugins that create
 * unprefixed tables) are site data only when someone declares them by their real name: a
 * declared name matches the prefixed table first, then an unprefixed table of that name.
 * Undeclared unprefixed tables may belong to another application sharing the database, so
 * they are never taken — {@see unassignedRaw()} lists them for warnings (BK-2 decision #3).
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-data-groups
 * @aidlc-adr backup-engine_data-group-registry
 */
final class DataGroupRegistry
{
    private const LABEL_PREFIX = 'admin.backup.group.';

    /** @var array<string, array> */
    private array $declared;

    /** @var list<string> */
    private array $excluded;

    /** @var callable(): list<string> */
    private $tableLister;

    /** @var callable(): array<string, array> */
    private $manifestReader;

    /** @var callable(): list<string> */
    private $rawTableLister;

    /** @var array{groups: array<string, DataGroup>, claims: array<string, list<string>>, unassigned: list<string>, unassigned_raw: list<string>}|null */
    private ?array $resolved = null;

    /**
     * @param array<string, array>|null $declared       Group key => ['label' => ?, 'tables' => [...]]; null = config.
     * @param list<string>|null         $excluded       Tables never backed up; null = config.
     * @param callable|null             $tableLister    Returns existing tables without prefix; null = database.
     * @param callable|null             $manifestReader Returns "<Type>/<Key>" => gp247.json array; null = local extensions.
     * @param callable|null             $rawTableLister Returns existing tables without the prefix in their real name;
     *                                                  null = database when $tableLister is null, none otherwise.
     */
    public function __construct(
        ?array $declared = null,
        ?array $excluded = null,
        ?callable $tableLister = null,
        ?callable $manifestReader = null,
        ?callable $rawTableLister = null,
    ) {
        $this->rawTableLister = $rawTableLister ?? ($tableLister === null ? [self::class, 'databaseRawTables'] : static fn () => []);
        $this->declared = $declared ?? (array) config('gp247-config.backup.data_groups', []);
        $this->excluded = array_values(array_unique($excluded ?? (array) config('gp247-config.backup.excluded_tables', [])));
        $this->tableLister = $tableLister ?? [self::class, 'databaseTables'];
        $this->manifestReader = $manifestReader ?? [self::class, 'extensionManifests'];
    }

    /**
     * Append tables to a group (and optionally to the excluded list) in the config registry.
     * Called by front / shop at boot; does nothing on a core that has no backup registry yet.
     *
     * @param string       $group    Group key ("content", "orders"…); tables of the same key are merged.
     * @param list<string> $tables   Tables without GP247_DB_PREFIX.
     * @param list<string> $excluded Tables of the caller that must never be backed up.
     * @return void
     */
    public static function declare(string $group, array $tables, array $excluded = []): void
    {
        if (!config()->has('gp247-config.backup.data_groups')) {
            return;
        }
        $path = 'gp247-config.backup.data_groups.'.$group.'.tables';
        config([$path => self::merge((array) config($path, []), $tables)]);
        if ($excluded) {
            config(['gp247-config.backup.excluded_tables' => self::merge((array) config('gp247-config.backup.excluded_tables', []), $excluded)]);
        }
    }

    /**
     * Declare child tables of a parent table, for restoring deleted records: when a parent row is
     * added back, the archive rows of each child whose foreign-key column holds its key come with
     * it (US-backup-restore-deleted-records). Called by the package that owns the tables at boot;
     * does nothing on a core without a backup registry.
     *
     * @param string                $parent   Parent table without GP247_DB_PREFIX.
     * @param array<string, string> $children Child table (without prefix) => column holding the parent key.
     * @return void
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-deleted-records
     */
    public static function relate(string $parent, array $children): void
    {
        if (!config()->has('gp247-config.backup.data_groups')) {
            return;
        }
        // WHY a whole-array write: dot-notation would split a key containing dots.
        $relations = (array) config('gp247-config.backup.relations', []);
        $relations[$parent] = array_merge((array) ($relations[$parent] ?? []), $children);
        config(['gp247-config.backup.relations' => $relations]);
    }

    /**
     * @param string $parent Parent table without prefix.
     * @return array<string, string> Declared child table => column holding the parent key.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-deleted-records
     */
    public static function relationsOf(string $parent): array
    {
        $children = ((array) config('gp247-config.backup.relations', []))[$parent] ?? [];

        return array_filter(array_map('strval', (array) $children), fn ($column, $child) => is_string($child) && $child !== '' && $column !== '', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param string $table Table without prefix (or real name of a declared unprefixed table).
     * @return string|null Key of the group that owns it, null for "Other" / unknown tables.
     *
     * @aidlc-unit backup-restore
     * @aidlc-story US-backup-restore-table-restore
     */
    public function groupOf(string $table): ?string
    {
        return $this->resolve()['claims'][$table][0] ?? null;
    }

    /**
     * @return array<string, DataGroup> Non-empty groups keyed by group key.
     */
    public function groups(): array
    {
        return $this->resolve()['groups'];
    }

    /**
     * @return list<string> Existing prefixed tables no group claims and none excludes ("Other").
     */
    public function unassigned(): array
    {
        return $this->resolve()['unassigned'];
    }

    /**
     * @return list<string> Existing unprefixed tables nobody declared and none excludes — not backed up.
     */
    public function unassignedRaw(): array
    {
        return $this->resolve()['unassigned_raw'];
    }

    /**
     * @return list<string> Tables that are never backed up.
     */
    public function excluded(): array
    {
        return $this->excluded;
    }

    /**
     * @return array<string, list<string>> Table => group keys, for existing tables claimed by more than one group.
     */
    public function duplicates(): array
    {
        return array_filter($this->resolve()['claims'], fn (array $groups) => count($groups) > 1);
    }

    /**
     * @return array{groups: array<string, DataGroup>, claims: array<string, list<string>>, unassigned: list<string>}
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $existing = array_values((array) ($this->tableLister)());
        $usable = array_flip(array_diff($existing, $this->excluded));
        // A prefixed table wins over an unprefixed one of the same name.
        $rawExisting = array_values(array_filter((array) ($this->rawTableLister)(), fn ($t) => !isset($usable[$t])));
        $rawUsable = array_flip(array_diff($rawExisting, $this->excluded));
        $claims = [];
        $members = [];
        $labels = [];

        foreach ($this->declarations() as [$key, $label, $tables]) {
            $labels[$key] ??= $label;
            foreach ($tables as $table) {
                if (!isset($usable[$table]) && !isset($rawUsable[$table])) {
                    continue;
                }
                if (!in_array($key, $claims[$table] ?? [], true)) {
                    $claims[$table][] = $key;
                }
                if (count($claims[$table]) === 1 && !in_array($table, $members[$key] ?? [], true)) {
                    $members[$key][] = $table;
                }
            }
        }

        $groups = [];
        foreach ($members as $key => $tables) {
            $raw = array_values(array_filter($tables, fn ($t) => !isset($usable[$t])));
            $groups[$key] = new DataGroup($key, $labels[$key], $tables, $raw);
        }

        $unassigned = array_values(array_filter($existing, fn ($t) => isset($usable[$t]) && !isset($claims[$t])));
        $unassignedRaw = array_values(array_filter($rawExisting, fn ($t) => isset($rawUsable[$t]) && !isset($claims[$t])));

        return $this->resolved = ['groups' => $groups, 'claims' => $claims, 'unassigned' => $unassigned, 'unassigned_raw' => $unassignedRaw];
    }

    /**
     * @return list<array{0: string, 1: string, 2: list<string>}> [group key, label, tables] in claim order.
     */
    private function declarations(): array
    {
        $out = [];
        foreach ($this->declared as $key => $group) {
            $out[] = [(string) $key, (string) ($group['label'] ?? self::LABEL_PREFIX.$key), array_values((array) ($group['tables'] ?? []))];
        }

        foreach ((array) ($this->manifestReader)() as $id => $manifest) {
            $tables = $manifest['dataTables'] ?? null;
            if (!$tables || !is_array($tables)) {
                continue;
            }
            [$type, $extKey] = array_pad(explode('/', (string) $id, 2), 2, '');
            if (isset($tables['tables'])) {
                $key = (string) ($tables['group'] ?? 'ext.'.strtolower($type).'.'.$extKey);
                $out[] = [$key, self::LABEL_PREFIX.$key, array_values((array) $tables['tables'])];
                continue;
            }
            $out[] = ['ext.'.strtolower($type).'.'.$extKey, (string) ($manifest['name'] ?? $extKey), array_values($tables)];
        }

        return $out;
    }

    /**
     * Existing tables of the GP247 connection that carry GP247_DB_PREFIX, prefix removed.
     *
     * @return list<string>
     */
    public static function databaseTables(): array
    {
        $prefix = (string) GP247_DB_PREFIX;
        $tables = [];
        foreach (self::currentSchemaTables() as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name !== '' && ($prefix === '' || str_starts_with($name, $prefix))) {
                $tables[] = substr($name, strlen($prefix));
            }
        }

        return $tables;
    }

    /**
     * Existing tables of the GP247 connection WITHOUT GP247_DB_PREFIX (real names). Empty when
     * the prefix is empty — then every table is already listed by databaseTables().
     *
     * @return list<string>
     */
    public static function databaseRawTables(): array
    {
        $prefix = (string) GP247_DB_PREFIX;
        if ($prefix === '') {
            return [];
        }
        $tables = [];
        foreach (self::currentSchemaTables() as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name !== '' && !str_starts_with($name, $prefix)) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * Tables of the GP247 connection's own database only. Since Laravel 12, getTables() without
     * a schema lists every database the account can see — on a shared MySQL server that would
     * pull other sites' tables into this site's backup.
     *
     * @return list<array<string, mixed>>
     */
    public static function currentSchemaTables(): array
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);

        return $schema->getTables($schema->getCurrentSchemaListing());
    }

    /**
     * gp247.json of every extension present on disk, installed or not — a disabled
     * extension's tables still hold data worth backing up.
     *
     * @return array<string, array> "<Type>/<Key>" => manifest.
     */
    public static function extensionManifests(): array
    {
        if (!function_exists('gp247_extension_get_all_local')) {
            return [];
        }
        $manifests = [];
        foreach (['Plugins', 'Templates'] as $type) {
            foreach (array_keys(gp247_extension_get_all_local($type)) as $key) {
                $file = app_path('GP247/'.$type.'/'.$key.'/gp247.json');
                $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                if (is_array($data)) {
                    $manifests[$type.'/'.$key] = $data;
                }
            }
        }

        return $manifests;
    }

    /**
     * @param list<string> $current
     * @param list<string> $add
     * @return list<string>
     */
    private static function merge(array $current, array $add): array
    {
        return array_values(array_unique(array_merge($current, $add)));
    }
}
