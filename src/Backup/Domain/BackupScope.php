<?php

namespace GP247\Core\Backup\Domain;

/**
 * What one backup covers: data groups, file groups and the "leave logs out" option, for one
 * of the three levels (snapshot, selective, full). An empty selection is never a valid scope.
 * Group keys are checked against the registry when the scope is resolved, not here.
 *
 * @aidlc-unit backup-engine
 * @aidlc-story US-backup-engine-quick-snapshot, US-backup-engine-selective-backup, US-backup-engine-full-backup
 * @aidlc-adr backup-engine_data-group-registry
 */
final class BackupScope
{
    public const DEFAULT_SNAPSHOT_GROUP = 'config';

    /**
     * @param string       $kind        Archive::KIND_*.
     * @param list<string> $groups      Data group keys (empty for full = every group).
     * @param list<string> $files       File group keys (empty for full = every file group).
     * @param bool         $excludeLogs Full backup without the log group.
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $groups,
        public readonly array $files,
        public readonly bool $excludeLogs,
    ) {
    }

    /**
     * One data group, no files — the "quick snapshot" (Configuration by default).
     *
     * @param string $group
     * @return self
     */
    public static function snapshot(string $group = self::DEFAULT_SNAPSHOT_GROUP): self
    {
        return new self(Archive::KIND_SNAPSHOT, self::keys([$group]), [], false);
    }

    /**
     * @param list<string> $groups Data group keys.
     * @param list<string> $files  File group keys.
     * @return self
     * @throws BackupRefused When nothing is selected.
     */
    public static function selective(array $groups, array $files = []): self
    {
        $groups = self::keys($groups);
        $files = self::keys($files);
        if ($groups === [] && $files === []) {
            throw new BackupRefused(BackupRefused::SCOPE_EMPTY);
        }

        return new self(Archive::KIND_SELECTIVE, $groups, $files, false);
    }

    /**
     * Every data group, the "Other" tables and every file group.
     *
     * @param bool $excludeLogs Leave the log group out (lighter archive).
     * @return self
     */
    public static function full(bool $excludeLogs = false): self
    {
        return new self(Archive::KIND_FULL, [], [], $excludeLogs);
    }

    /**
     * @return array{kind: string, groups: list<string>, files: list<string>, exclude_logs: bool}
     */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'groups' => $this->groups, 'files' => $this->files, 'exclude_logs' => $this->excludeLogs];
    }

    /**
     * @param array<string, mixed> $data From toArray().
     * @return self
     * @throws BackupRefused On an empty or unknown scope.
     */
    public static function fromArray(array $data): self
    {
        return match ($data['kind'] ?? null) {
            Archive::KIND_SNAPSHOT => self::snapshot((string) (($data['groups'] ?? [])[0] ?? self::DEFAULT_SNAPSHOT_GROUP)),
            Archive::KIND_SELECTIVE => self::selective((array) ($data['groups'] ?? []), (array) ($data['files'] ?? [])),
            Archive::KIND_FULL => self::full((bool) ($data['exclude_logs'] ?? false)),
            default => throw new BackupRefused(BackupRefused::SCOPE_EMPTY, 'kind'),
        };
    }

    /**
     * @param array<mixed> $keys
     * @return list<string> Trimmed, non-empty, unique, order kept.
     */
    private static function keys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $key = trim((string) $key);
            if ($key !== '' && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
