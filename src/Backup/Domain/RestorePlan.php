<?php

namespace GP247\Core\Backup\Domain;

use GP247\Core\Backup\Infrastructure\Format\RowCodec;

/**
 * What one restore does: the source archive, the level and what is selected.
 *
 *  - replace  (R0) replace whole data groups — a table selected alone brings its whole group;
 *  - undelete (R0) add back rows of one table (and their declared child rows) that are in the
 *              archive but no longer on the site — never changes an existing row;
 *  - config   (R1) replace the Configuration group, keeping the backup feature's own rows;
 *  - full     (R2) replace every table of a full archive, in maintenance mode.
 *
 * Names are table / group names as written in the archive (without GP247_DB_PREFIX); they are
 * checked against the archive and the site when the plan is resolved, not here.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-deleted-records, US-backup-restore-config-restore, US-backup-restore-full-database
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class RestorePlan
{
    public const REPLACE = 'replace';
    public const UNDELETE = 'undelete';
    public const CONFIG = 'config';
    public const FULL = 'full';

    public const LEVELS = [self::REPLACE, self::UNDELETE, self::CONFIG, self::FULL];

    public const CONFIG_GROUP = 'config';

    /**
     * @param string                $archiveId Archive (ULID).
     * @param string                $level     One of LEVELS.
     * @param list<string>          $groups    Data groups (replace).
     * @param list<string>          $tables    Tables (replace: expanded to their group; undelete: exactly one parent table).
     * @param list<int|string>|null $keys      Undelete: primary key values to add back, null = every missing row.
     */
    private function __construct(
        public readonly string $archiveId,
        public readonly string $level,
        public readonly array $groups,
        public readonly array $tables,
        public readonly ?array $keys,
    ) {
    }

    /**
     * @param string       $archiveId
     * @param list<string> $groups
     * @param list<string> $tables
     * @return self
     * @throws RestoreRefused When nothing is selected.
     */
    public static function replace(string $archiveId, array $groups, array $tables = []): self
    {
        $groups = self::names($groups);
        $tables = self::names($tables);
        if ($groups === [] && $tables === []) {
            throw new RestoreRefused(RestoreRefused::SCOPE_EMPTY);
        }

        return new self(self::id($archiveId), self::REPLACE, $groups, $tables, null);
    }

    /**
     * @param string                $archiveId
     * @param string                $table Parent table (archive name).
     * @param list<int|string>|null $keys  Primary key values, null = every missing row.
     * @return self
     */
    public static function undelete(string $archiveId, string $table, ?array $keys = null): self
    {
        $tables = self::names([$table]);
        if ($tables === []) {
            throw new RestoreRefused(RestoreRefused::SCOPE_EMPTY);
        }
        if ($keys !== null) {
            $keys = array_values(array_unique(array_filter($keys, fn ($k) => is_int($k) || (is_string($k) && $k !== '' && strlen($k) <= 255)), SORT_REGULAR));
            if ($keys === []) {
                throw new RestoreRefused(RestoreRefused::SCOPE_EMPTY, 'no key');
            }
        }

        return new self(self::id($archiveId), self::UNDELETE, [], $tables, $keys);
    }

    /**
     * @param string $archiveId
     * @return self
     */
    public static function config(string $archiveId): self
    {
        return new self(self::id($archiveId), self::CONFIG, [self::CONFIG_GROUP], [], null);
    }

    /**
     * @param string $archiveId
     * @return self
     */
    public static function full(string $archiveId): self
    {
        return new self(self::id($archiveId), self::FULL, [], [], null);
    }

    /**
     * @param array<string, mixed> $data From toArray().
     * @return self
     * @throws RestoreRefused When the data does not describe a valid plan.
     */
    public static function fromArray(array $data): self
    {
        $archiveId = (string) ($data['archive_id'] ?? '');

        return match ($data['level'] ?? null) {
            self::REPLACE => self::replace($archiveId, (array) ($data['groups'] ?? []), (array) ($data['tables'] ?? [])),
            self::UNDELETE => self::undelete($archiveId, (string) (((array) ($data['tables'] ?? []))[0] ?? ''), isset($data['keys']) ? (array) $data['keys'] : null),
            self::CONFIG => self::config($archiveId),
            self::FULL => self::full($archiveId),
            default => throw new RestoreRefused(RestoreRefused::LEVEL_INVALID),
        };
    }

    /**
     * @return array{archive_id: string, level: string, groups: list<string>, tables: list<string>, keys: list<int|string>|null}
     */
    public function toArray(): array
    {
        return [
            'archive_id' => $this->archiveId,
            'level' => $this->level,
            'groups' => $this->groups,
            'tables' => $this->tables,
            'keys' => $this->keys,
        ];
    }

    /**
     * @return bool The plan renames tables (everything except undelete).
     */
    public function swapsTables(): bool
    {
        return $this->level !== self::UNDELETE;
    }

    /**
     * @param string $archiveId
     * @return string
     * @throws RestoreRefused
     */
    private static function id(string $archiveId): string
    {
        if (preg_match('/^[0-9A-Z]{26}$/D', $archiveId) !== 1) {
            throw new RestoreRefused(RestoreRefused::NOT_FOUND, 'archive id');
        }

        return $archiveId;
    }

    /**
     * @param array<mixed> $names
     * @return list<string> Unique valid names.
     * @throws RestoreRefused When a name has characters a table or group name never has.
     */
    private static function names(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            // Group keys of extensions contain dots ("ext.plugins.Key").
            if (!RowCodec::validName(str_replace('.', '_', $name))) {
                throw new RestoreRefused(RestoreRefused::GROUP_UNKNOWN, 'name');
            }
            $out[$name] = true;
        }

        return array_keys($out);
    }
}
