<?php

namespace GP247\Core\Backup\Application;

use GP247\Core\Models\AdminConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * What is special about restoring admin_config (R1, and any restore that replaces the
 * Configuration group):
 *
 *  - the backup feature's own rows (key ring, signing key, switch, vault settings, restore ledger)
 *    are kept as they are NOW — restoring older ones would bring back an old key pair (new archives
 *    encrypted for a password nobody uses any more) and a signing key under which no newer archive
 *    verifies (contract engine↔restore, BK-1);
 *  - the difference shown before applying hides secret values (security = 1): only "changed";
 *  - after the swap the running site must read the restored values at once (memo + GP247 caches).
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-config-restore
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class ConfigRestoreRules
{
    public const TABLE = 'admin_config';
    public const BACKUP_CODE = 'backup';
    private const SHOWN_VALUE_CHARS = 200;

    /**
     * Decided on the REAL name: an archive cannot dodge the rule by listing admin_config under
     * another name (for example as an unprefixed "raw" table).
     *
     * @param string $real Real table name on this site.
     * @return bool It is this site's admin_config.
     */
    public static function isConfigTable(string $real): bool
    {
        return strcasecmp($real, GP247_DB_PREFIX . self::TABLE) === 0;
    }

    /**
     * Case-insensitive, like the database collation the SQL form of the rule runs under.
     *
     * @param array<string, mixed> $row admin_config row.
     * @return bool A row of the backup feature, never taken from an archive.
     */
    public static function isBackupRow(array $row): bool
    {
        return strcasecmp((string) ($row['code'] ?? ''), self::BACKUP_CODE) === 0 || stripos((string) ($row['key'] ?? ''), 'backup_') === 0;
    }

    /**
     * "Remember me" tokens in a restored table are emptied: a token revoked after the backup
     * (password change, sign-out everywhere) must not work again.
     *
     * @param ConnectionInterface $db
     * @param string              $shadow  Loaded shadow.
     * @param list<string>        $columns Its columns.
     * @return void
     */
    public static function clearSessionTokens(ConnectionInterface $db, string $shadow, array $columns): void
    {
        foreach (UndeleteService::CLEARED_COLUMNS as $column) {
            if (in_array($column, $columns, true)) {
                $db->table($shadow)->whereNotNull($column)->update([$column => null]);
            }
        }
    }

    /**
     * Replace the backup feature's rows of a loaded shadow by the site's current ones.
     * Current rows are inserted without their id (the archive may use those ids for other rows).
     *
     * @param ConnectionInterface $db
     * @param string              $live   Real name of admin_config.
     * @param string              $shadow Its loaded shadow.
     * @return int Rows kept from the site.
     */
    public static function keepBackupRows(ConnectionInterface $db, string $live, string $shadow): int
    {
        $db->table($shadow)->where(fn (Builder $q) => self::backupRows($q))->delete();
        $columns = array_values(array_diff(
            array_intersect($db->getSchemaBuilder()->getColumnListing($live), $db->getSchemaBuilder()->getColumnListing($shadow)),
            ['id']
        ));
        $rows = $db->table($live)->where(fn (Builder $q) => self::backupRows($q))->get($columns)->map(fn ($r) => (array) $r)->all();
        if ($rows !== []) {
            $db->table($shadow)->insert($rows);
        }

        return count($rows);
    }

    /**
     * Keys that differ between the archive and the site, without the backup feature's rows.
     * Secret rows (security = 1 on either side) carry no value, only the kind of change.
     *
     * @param iterable<array<string,mixed>> $archiveRows admin_config rows of the archive.
     * @param iterable<array<string,mixed>> $siteRows    Current admin_config rows.
     * @return list<array{store_id: string, key: string, change: string, secret: bool, archive: string|null, site: string|null}>
     */
    public static function diff(iterable $archiveRows, iterable $siteRows): array
    {
        $index = function (iterable $rows): array {
            $out = [];
            foreach ($rows as $row) {
                if (!self::isBackupRow($row)) {
                    $out[$row['store_id'] . "\0" . $row['key']] = $row;
                }
            }

            return $out;
        };
        $archive = $index($archiveRows);
        $site = $index($siteRows);

        $changes = [];
        foreach ($archive + $site as $id => $row) {
            $from = $site[$id] ?? null;
            $to = $archive[$id] ?? null;
            $change = match (true) {
                $from === null => 'added',
                $to === null => 'removed',
                (string) $from['value'] !== (string) $to['value'] => 'changed',
                default => null,
            };
            if ($change === null) {
                continue;
            }
            $secret = (int) ($from['security'] ?? 0) === 1 || (int) ($to['security'] ?? 0) === 1;
            $changes[] = [
                'store_id' => (string) $row['store_id'],
                'key' => (string) $row['key'],
                'change' => $change,
                'secret' => $secret,
                'archive' => $secret || $to === null ? null : self::shown($to['value']),
                'site' => $secret || $from === null ? null : self::shown($from['value']),
            ];
        }
        usort($changes, fn ($a, $b) => [$a['store_id'], $a['key']] <=> [$b['store_id'], $b['key']]);

        return $changes;
    }

    /**
     * Make the running site read the restored configuration now.
     *
     * @return void
     */
    public static function refreshCaches(): void
    {
        AdminConfig::forgetMemo();
        if (function_exists('gp247_cache_clear')) {
            gp247_cache_clear('cache_all');
        }
    }

    /**
     * @param Builder $query
     * @return void
     */
    private static function backupRows(Builder $query): void
    {
        // WHY a range and not LIKE 'backup\_%': SQLite has no default LIKE escape character.
        // "`" is the character right after "_", so the range is exactly the keys starting "backup_".
        $query->where('code', self::BACKUP_CODE)
            ->orWhere(fn (Builder $q) => $q->where('key', '>=', 'backup_')->where('key', '<', 'backup`'));
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function shown(mixed $value): string
    {
        $value = (string) $value;

        return mb_strlen($value) > self::SHOWN_VALUE_CHARS ? mb_substr($value, 0, self::SHOWN_VALUE_CHARS) . '…' : $value;
    }
}
