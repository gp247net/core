<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Backup\Domain\BackupRefused;
use GP247\Core\Backup\Domain\BackupScope;
use GP247\Core\Backup\Domain\FileGroup;
use GP247\Core\Backup\Infrastructure\Format\RowCodec;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a scope into the concrete list of tables (name in the archive ⇄ real name) and file
 * groups, through the data-group registry. Prefixed tables are stored without the prefix;
 * unprefixed tables that are taken (Laravel `migrations`, declared plugin tables) keep their
 * real name and are listed as "raw" so a restore never adds the prefix to them.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-data-groups, US-backup-engine-selective-backup, US-backup-engine-full-backup, US-backup-engine-quick-snapshot
 * @aidlc-adr backup-engine_data-group-registry
 */
final class ScopeResolver
{
    public const LOG_GROUP = 'logs';

    private DataGroupRegistry $registry;

    /** @var array<string, array>|null */
    private ?array $fileConfig;

    /**
     * @param DataGroupRegistry|null   $registry
     * @param array<string, array>|null $fileConfig File group config (null = gp247-config.backup.file_groups).
     * @param string|null              $migrationsTable Real name of Laravel's migration table (null = config).
     */
    public function __construct(?DataGroupRegistry $registry = null, ?array $fileConfig = null, private ?string $migrationsTable = null)
    {
        $this->registry = $registry ?? new DataGroupRegistry;
        $this->fileConfig = $fileConfig;
    }

    /**
     * @return DataGroupRegistry
     */
    public function registry(): DataGroupRegistry
    {
        return $this->registry;
    }

    /**
     * @return array<string, FileGroup> Configured file groups (paths may be missing on disk).
     */
    public function fileGroups(): array
    {
        $groups = [];
        foreach ($this->fileConfig ?? (array) config('gp247-config.backup.file_groups', []) as $key => $entry) {
            $entry = (array) $entry;
            $path = $entry['path'] ?? null;
            if ($path === null && array_key_exists('disk', $entry)) {
                $path = self::diskRoot($entry['disk']);
            }
            if (is_string($path) && $path !== '' && RowCodec::validName((string) $key)) {
                $groups[(string) $key] = new FileGroup((string) $key, $path, array_values((array) ($entry['exclude'] ?? [])));
            }
        }

        return $groups;
    }

    /**
     * @param BackupScope $scope
     * @return array{
     *     tables: list<array{name: string, real: string}>,
     *     raw: list<string>,
     *     groups: list<string>,
     *     files: list<array{key: string, path: string, exclude: list<string>}>,
     *     warnings: list<string>,
     *     unassigned_raw: list<string>
     * }
     * @throws BackupRefused When a group is unknown or the scope selects nothing.
     */
    public function resolve(BackupScope $scope): array
    {
        $all = $this->registry->groups();
        $fileGroups = $this->fileGroups();
        $full = $scope->kind === 'full';

        if ($full) {
            $groupKeys = array_values(array_filter(array_keys($all), fn ($k) => !($scope->excludeLogs && $k === self::LOG_GROUP)));
            $fileKeys = array_keys($fileGroups);
        } else {
            foreach ($scope->groups as $key) {
                if (!isset($all[$key])) {
                    throw new BackupRefused(BackupRefused::GROUP_UNKNOWN, 'data group ' . $key);
                }
            }
            foreach ($scope->files as $key) {
                if (!isset($fileGroups[$key])) {
                    throw new BackupRefused(BackupRefused::GROUP_UNKNOWN, 'file group ' . $key);
                }
            }
            $groupKeys = $scope->groups;
            $fileKeys = $scope->files;
        }

        $tables = [];
        $raw = [];
        $warnings = [];
        $add = function (string $name, string $real, bool $isRaw) use (&$tables, &$raw, &$warnings) {
            if (isset($tables[$name])) {
                if ($tables[$name]['real'] !== $real) {
                    $warnings[] = 'name_collision:' . $real;
                }

                return;
            }
            if (!RowCodec::validName($name)) {
                $warnings[] = 'name_unsupported:' . $real;

                return;
            }
            $tables[$name] = ['name' => $name, 'real' => $real];
            if ($isRaw) {
                $raw[] = $name;
            }
        };

        foreach ($groupKeys as $key) {
            $group = $all[$key];
            foreach ($group->tables as $table) {
                $add($table, $group->realName($table), in_array($table, $group->raw, true));
            }
        }
        if ($full) {
            foreach ($this->registry->unassigned() as $table) {
                $add($table, GP247_DB_PREFIX . $table, false);
            }
            $migrations = $this->migrationsName();
            if ($migrations !== '' && $this->tableExists($migrations)) {
                $prefixed = GP247_DB_PREFIX !== '' && str_starts_with($migrations, GP247_DB_PREFIX);
                $add($prefixed ? substr($migrations, strlen(GP247_DB_PREFIX)) : $migrations, $migrations, !$prefixed && GP247_DB_PREFIX !== '');
            }
        }

        $files = [];
        foreach ($fileKeys as $key) {
            $group = $fileGroups[$key];
            if ($group->missing()) {
                $warnings[] = 'file_group_missing:' . $key;
                continue;
            }
            $files[] = ['key' => $group->key, 'path' => $group->path, 'exclude' => $group->exclude];
        }

        if ($tables === [] && $files === []) {
            throw new BackupRefused(BackupRefused::SCOPE_EMPTY, 'nothing to back up');
        }

        return [
            'tables' => array_values($tables),
            'raw' => $raw,
            'groups' => $full ? $groupKeys : $scope->groups,
            'files' => $files,
            'warnings' => $warnings,
            // The migration table is always taken by a full backup: not an "untaken" table.
            'unassigned_raw' => array_values(array_diff($this->registry->unassignedRaw(), [$this->migrationsName()])),
        ];
    }

    /**
     * @return string Real name of Laravel's migration table.
     */
    private function migrationsName(): string
    {
        return $this->migrationsTable ?? self::configuredMigrationsTable();
    }

    /**
     * @param string $table Real name.
     * @return bool
     */
    private function tableExists(string $table): bool
    {
        try {
            return Schema::connection(GP247_DB_CONNECTION)->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return string
     */
    private static function configuredMigrationsTable(): string
    {
        $setting = config('database.migrations');

        return (string) (is_array($setting) ? ($setting['table'] ?? 'migrations') : ($setting ?: 'migrations'));
    }

    /**
     * @param mixed $disk Disk name, or null for the upload disk.
     * @return string|null Root of a local disk.
     */
    private static function diskRoot(mixed $disk): ?string
    {
        $name = (string) ($disk ?? config('lfm.disk', 'gp247'));
        $config = config('filesystems.disks.' . $name);
        if (!is_array($config) || ($config['driver'] ?? null) !== 'local' || !is_string($config['root'] ?? null)) {
            return null;
        }

        return $config['root'];
    }
}
