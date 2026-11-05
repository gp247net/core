<?php

namespace GP247\Core\Backup\Infrastructure\Store;

use GP247\Core\Models\AdminConfig;

/**
 * Two rows of admin_config (global store, group "backup" — so no migration is needed and a
 * configuration restore keeps them like every other row of the backup feature):
 *
 *  - the UNDO POINT: the last restore that replaced tables — which tables, until when the old
 *    copies T__bko are kept. Only a restore that replaced tables writes it; an undelete or a
 *    failed restore never ends the undo window of an earlier restore;
 *  - the LAST RESULT of any restore (done / failed / undone), shown by the admin screen;
 *  - tables left in an unclear state after a failure are listed as QUARANTINED and never
 *    dropped automatically.
 *
 * Holds no secret and no row data — table names, counts and reason codes only.
 *
 * @aidlc-unit backup-restore
 * @aidlc-story US-backup-restore-table-restore, US-backup-restore-full-database
 * @aidlc-adr backup-restore_shadow-table-swap
 */
final class RestoreLedger
{
    public const UNDO_KEY = 'backup_restore_undo';
    public const LAST_KEY = 'backup_restore_last';
    public const QUARANTINE_KEY = 'backup_restore_quarantine';

    /**
     * @return array<string, mixed>|null {job_id, archive_id, level, tables, undo_until, swapped_at, …}
     */
    public function undoPoint(): ?array
    {
        return $this->read(self::UNDO_KEY);
    }

    /**
     * @param array<string, mixed>|null $entry Null = no undo point any more.
     * @return void
     */
    public function recordUndoPoint(?array $entry): void
    {
        $this->write(self::UNDO_KEY, $entry);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        return $this->read(self::LAST_KEY);
    }

    /**
     * @param array<string, mixed> $entry
     * @return void
     */
    public function recordLast(array $entry): void
    {
        $this->write(self::LAST_KEY, $entry);
    }

    /**
     * @return list<string> Real table names whose copies must be kept for a person to inspect.
     */
    public function quarantined(): array
    {
        return array_values(array_map('strval', (array) ($this->read(self::QUARANTINE_KEY)['tables'] ?? [])));
    }

    /**
     * @param list<string> $tables
     * @return void
     */
    public function quarantine(array $tables): void
    {
        $this->write(self::QUARANTINE_KEY, ['tables' => array_values(array_unique([...$this->quarantined(), ...$tables])), 'since' => now()->toIso8601String()]);
    }

    /**
     * @param string $key
     * @return array<string, mixed>|null
     */
    private function read(string $key): ?array
    {
        $raw = AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)->where('key', $key)->value('value');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param string                    $key
     * @param array<string, mixed>|null $entry
     * @return void
     */
    private function write(string $key, ?array $entry): void
    {
        AdminConfig::setConfigValue(AdminConfigKeyStore::GROUP, $key, GP247_STORE_ID_GLOBAL, $entry === null ? '' : (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
