<?php

namespace GP247\Core\Backup\Application;

use Carbon\Carbon;
use GP247\Core\Backup\Domain\Archive;
use GP247\Core\Backup\Domain\CompatibilityGate;
use GP247\Core\Backup\Domain\RestorePlan;
use GP247\Core\Backup\Domain\RestoreRefused;
use GP247\Core\Backup\Infrastructure\Database\SchemaInspector;
use GP247\Core\Backup\Infrastructure\Database\TableSwapper;
use GP247\Core\Backup\Infrastructure\Format\ArchiveReader;
use GP247\Core\Backup\Infrastructure\Format\RowCodec;
use GP247\Core\Backup\Infrastructure\Install\BackupSchema;

/**
 * Turns a restore plan into the concrete tables it replaces, and builds the preview shown before
 * anything is applied (logical design backup-restore §3 RestorePlanner).
 *
 * Names: a table name in the archive is the name without GP247_DB_PREFIX; tables listed in
 * meta.raw_tables keep their real name. Only tables of the CURRENT database are touched; a table
 * the archive has and the site lacks is skipped and listed, never created; excluded tables
 * (tokens, sessions, the backup feature's own tables) are never replaced, whatever the archive holds.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database, US-backup-restore-version-compatibility
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class RestorePlanner
{
    /** Columns that hold sign-in secrets: replacing such a table brings back accounts / keys revoked since. */
    private const CREDENTIAL_COLUMNS = ['password', 'apikey', 'api_key', 'apikey_hash', 'secret', 'token'];

    private DataGroupRegistry $registry;

    /**
     * @param SchemaInspector        $schema
     * @param DataGroupRegistry|null $registry
     */
    public function __construct(private readonly SchemaInspector $schema, ?DataGroupRegistry $registry = null)
    {
        $this->registry = $registry ?? new DataGroupRegistry;
    }

    /**
     * @param RestorePlan   $plan
     * @param ArchiveReader $reader Opened archive.
     * @return array{tables: list<array{name: string, real: string, columns: list<string>, rows: int}>,
     *               absent: list<string>, not_in_archive: list<string>, excluded: list<string>, groups: list<string>}
     * @throws RestoreRefused
     */
    public function resolve(RestorePlan $plan, ArchiveReader $reader): array
    {
        $manifest = $reader->manifest();
        $archiveTables = $manifest['tables'];

        [$names, $groups] = match ($plan->level) {
            RestorePlan::FULL => [array_map('strval', array_keys($archiveTables)), array_values((array) ($manifest['meta']['groups'] ?? []))],
            RestorePlan::UNDELETE => [$plan->tables, []],
            default => $this->groupNames($plan),
        };

        $excluded = $this->excludedRealNames();
        $out = ['tables' => [], 'absent' => [], 'not_in_archive' => [], 'excluded' => [], 'groups' => $groups];
        foreach ($names as $name) {
            if (!isset($archiveTables[$name])) {
                $out['not_in_archive'][] = $name;
                continue;
            }
            $real = $this->realName($name, $manifest);
            if (isset($excluded[strtolower($real)]) || TableSwapper::isCopy($real) || !RowCodec::validName($real)) {
                $out['excluded'][] = $name;
                continue;
            }
            if (!$this->schema->hasTable($real)) {
                $out['absent'][] = $name;
                continue;
            }
            $out['tables'][] = ['name' => $name, 'real' => $real, 'columns' => $archiveTables[$name]['columns'], 'rows' => $archiveTables[$name]['rows']];
        }
        if ($out['tables'] === []) {
            throw new RestoreRefused(RestoreRefused::NOTHING_TO_RESTORE, '', ['absent' => $out['absent'], 'not_in_archive' => $out['not_in_archive']]);
        }

        return $out;
    }

    /**
     * Everything the person restoring should see before confirming, with the resolved plan.
     *
     * @param RestorePlan          $plan
     * @param ArchiveReader        $reader
     * @param object               $archive  Catalogue row.
     * @param array<string, mixed> $operator ['id' => …, 'username' => …] of the admin restoring (R2 sign-in hint).
     * @return array{preview: array<string, mixed>, resolved: array}
     * @throws RestoreRefused When the plan cannot be resolved at all.
     */
    public function inspect(RestorePlan $plan, ArchiveReader $reader, object $archive, array $operator = []): array
    {
        $resolved = $this->resolve($plan, $reader);
        $manifest = $reader->manifest();
        $meta = $manifest['meta'];
        $compat = CompatibilityGate::check(
            (array) ($meta['migrations'] ?? []),
            $this->schema->migrations(SiteVersions::migrationsTable()),
            (array) ($meta['packages'] ?? []),
            SiteVersions::packages()
        );
        [$incoming, $ownFk] = $this->foreignKeys($plan, array_column($resolved['tables'], 'real'));

        $preview = [
            'archive' => [
                'id' => $plan->archiveId,
                'kind' => (string) $archive->kind,
                'created_at' => (string) $manifest['created_at'],
                'consistency' => (string) ($meta['consistency'] ?? $archive->consistency ?? ''),
                'warnings' => array_values((array) ($meta['warnings'] ?? [])),
            ],
            'level' => $plan->level,
            'groups' => $resolved['groups'],
            'tables' => $this->tableStats($resolved['tables'], self::localTime((string) $manifest['created_at'])),
            'absent' => $resolved['absent'],
            'not_in_archive' => $resolved['not_in_archive'],
            'excluded' => $resolved['excluded'],
            'compatibility' => $compat,
            'foreign_keys_outside' => $incoming,
            'foreign_keys_own' => $ownFk,
            // The replaced tables stay as the undo copy: the database needs about this much more.
            'extra_bytes' => $plan->swapsTables() ? $this->schema->bytes(array_column($resolved['tables'], 'real')) : 0,
            'file_groups_not_restored' => array_keys((array) ($manifest['files'] ?? [])),
            'refusals' => array_values(array_filter([
                $compat['status'] === CompatibilityGate::NEWER ? RestoreRefused::ARCHIVE_NEWER : null,
                $incoming !== [] ? RestoreRefused::FK_OUTSIDE_SCOPE : null,
                $ownFk !== [] ? RestoreRefused::FK_NOT_SUPPORTED : null,
            ])),
        ];

        return ['preview' => $preview + $this->accountNotes($plan, $resolved['tables'], $reader, $operator), 'resolved' => $resolved];
    }

    /**
     * @param RestorePlan          $plan
     * @param ArchiveReader        $reader
     * @param object               $archive
     * @param array<string, mixed> $operator
     * @return array<string, mixed>
     */
    public function preview(RestorePlan $plan, ArchiveReader $reader, object $archive, array $operator = []): array
    {
        return $this->inspect($plan, $reader, $archive, $operator)['preview'];
    }

    /**
     * @param RestorePlan $plan
     * @param object      $archive Catalogue row.
     * @return void
     * @throws RestoreRefused When the archive cannot be the source of this level.
     */
    public static function assertSource(RestorePlan $plan, object $archive): void
    {
        if ((string) $archive->status !== Archive::STATUS_OK) {
            throw new RestoreRefused(RestoreRefused::ARCHIVE_NOT_READY, (string) $archive->status);
        }
        if ($plan->level === RestorePlan::FULL && (string) $archive->kind !== Archive::KIND_FULL) {
            throw new RestoreRefused(RestoreRefused::ARCHIVE_KIND, (string) $archive->kind);
        }
    }

    /**
     * @param string        $parent Archive name of the parent table.
     * @param ArchiveReader $reader
     * @return list<array{name: string, real: string, column: string}> Declared children present in the archive and on the site.
     */
    public function children(string $parent, ArchiveReader $reader): array
    {
        $manifest = $reader->manifest();
        $excluded = $this->excludedRealNames();
        $out = [];
        foreach (DataGroupRegistry::relationsOf($parent) as $child => $column) {
            $real = $this->realName($child, $manifest);
            if (isset($manifest['tables'][$child]) && !isset($excluded[strtolower($real)]) && $this->schema->hasTable($real)
                && in_array($column, $manifest['tables'][$child]['columns'], true)) {
                $out[] = ['name' => $child, 'real' => $real, 'column' => $column];
            }
        }

        return $out;
    }

    /**
     * @param list<array{name: string, real: string, columns: list<string>, rows: int}> $tables
     * @param string                                                                    $since Backup time (local).
     * @return list<array<string, mixed>>
     */
    private function tableStats(array $tables, string $since): array
    {
        $out = [];
        foreach ($tables as $table) {
            $siteColumns = $this->schema->columns($table['real']);
            $out[] = [
                'name' => $table['name'],
                'rows_site' => (int) $this->schema->db()->table($table['real'])->count(),
                'rows_archive' => $table['rows'],
                'rows_new_since_backup' => $this->schema->rowsChangedAfter($table['real'], $siteColumns, $since),
                'columns_ignored' => array_values(array_diff($table['columns'], $siteColumns)),
                'columns_defaulted' => array_values(array_diff($siteColumns, $table['columns'])),
            ];
        }

        return $out;
    }

    /**
     * @param RestorePlan  $plan
     * @param list<string> $reals
     * @return array{0: list<array{table: string, references: string}>, 1: list<string>} Incoming keys, tables with their own keys (MySQL).
     */
    private function foreignKeys(RestorePlan $plan, array $reals): array
    {
        if (!$plan->swapsTables()) {
            return [[], []];
        }
        $incoming = $this->schema->incomingForeignKeys($reals);
        // WHY: MySQL CREATE TABLE … LIKE does not copy foreign keys — the restored table would lose them.
        $own = $this->schema->isMysql() ? array_values(array_filter($reals, fn ($t) => $this->schema->hasOutgoingForeignKeys($t))) : [];

        return [$incoming, $own];
    }

    /**
     * Configuration difference, sign-in hint and the tables whose accounts / keys come back.
     *
     * @param RestorePlan          $plan
     * @param list<array>          $tables Resolved tables.
     * @param ArchiveReader        $reader
     * @param array<string, mixed> $operator
     * @return array<string, mixed>
     */
    private function accountNotes(RestorePlan $plan, array $tables, ArchiveReader $reader, array $operator): array
    {
        if (!$plan->swapsTables()) {
            return [];
        }
        $notes = ['credential_tables' => array_values(array_map(
            fn ($t) => $t['name'],
            array_filter($tables, fn ($t) => array_intersect(self::CREDENTIAL_COLUMNS, $t['columns']) !== [])
        ))];
        foreach ($tables as $table) {
            if (ConfigRestoreRules::isConfigTable($table['real'])) {
                $notes['config_changes'] = ConfigRestoreRules::diff(
                    $reader->rows($table['name']),
                    $this->schema->db()->table($table['real'])->get()->map(fn ($r) => (array) $r)
                );
            }
            if ($table['real'] === GP247_DB_PREFIX . 'admin_user') {
                $notes['operator_in_archive'] = $this->operatorInArchive($reader, $table['name'], $operator);
            }
        }

        return $notes;
    }

    /**
     * A table selected alone brings its whole group (rows of related tables are replaced together).
     *
     * @param RestorePlan $plan
     * @return array{0: list<string>, 1: list<string>}
     * @throws RestoreRefused When a group is unknown on this site.
     */
    private function groupNames(RestorePlan $plan): array
    {
        $all = $this->registry->groups();
        $groups = $plan->groups;
        $names = [];
        foreach ($plan->tables as $table) {
            $group = $this->registry->groupOf($table);
            if ($group === null) {
                $names[] = $table;
            } elseif (!in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }
        foreach ($groups as $key) {
            if (!isset($all[$key])) {
                throw new RestoreRefused(RestoreRefused::GROUP_UNKNOWN, $key);
            }
            array_push($names, ...$all[$key]->tables);
        }

        return [array_values(array_unique($names)), $groups];
    }

    /**
     * @param string               $name     Table name in the archive.
     * @param array<string, mixed> $manifest
     * @return string Real name on this site: unprefixed for raw tables, prefixed otherwise.
     */
    private function realName(string $name, array $manifest): string
    {
        $raw = in_array($name, array_map('strval', (array) ($manifest['meta']['raw_tables'] ?? [])), true);

        return $raw ? $name : GP247_DB_PREFIX . $name;
    }

    /**
     * @return array<string, true> Lower-case real names of tables a restore never replaces.
     */
    private function excludedRealNames(): array
    {
        $out = [];
        // WHY the backup tables always: replacing the backup feature's own bookkeeping would drop
        // the very job running the restore, whatever the excluded list says.
        foreach ([...$this->registry->excluded(), ...BackupSchema::TABLES] as $table) {
            $out[strtolower(GP247_DB_PREFIX . $table)] = true;
            $out[strtolower($table)] = true;
        }

        return $out;
    }

    /**
     * @param ArchiveReader        $reader
     * @param string               $name     Archive name of admin_user.
     * @param array<string, mixed> $operator
     * @return bool|null Whether the admin restoring exists in the archive with the same username; null = unknown.
     */
    private function operatorInArchive(ArchiveReader $reader, string $name, array $operator): ?bool
    {
        if (!isset($operator['id'], $operator['username'])) {
            return null;
        }
        foreach ($reader->rows($name) as $row) {
            if ((string) ($row['id'] ?? '') === (string) $operator['id']) {
                return (string) ($row['username'] ?? '') === (string) $operator['username'];
            }
        }

        return false;
    }

    /**
     * @param string $utc Archive time (ISO 8601, UTC).
     * @return string The same instant in the application timezone, as the database stores timestamps.
     */
    private static function localTime(string $utc): string
    {
        return Carbon::parse($utc)->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }
}
